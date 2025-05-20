<?php

namespace Crm\FamilyModule\Models;

use Crm\ApplicationModule\Models\Database\Selection;
use Crm\ApplicationModule\Repositories\CacheRepository;
use Crm\FamilyModule\Models\ConfigurableFamilySubscription\PaymentItemsConfig;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repositories\PaymentItemMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeItemMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class FamilyRequests
{
    public const NEXT_FAMILY_SUBSCRIPTION_META = 'next_family_subscription_id';

    public const KEEP_REQUESTS_UNACTIVATED_PAYMENT_META = 'keep_requests_unactivated';

    public const PAYMENT_ITEM_META_SLAVE_SUBSCRIPTION_TYPE_ID = 'slave_subscription_type_id';

    public function __construct(
        private CacheRepository $cacheRepository,
        private FamilyRequestsRepository $familyRequestsRepository,
        private PaymentsRepository $paymentsRepository,
        private SubscriptionsRepository $subscriptionsRepository,
        private SubscriptionTypesRepository $subscriptionTypesRepository,
        private FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        private PaymentMetaRepository $paymentMetaRepository,
        private PaymentItemMetaRepository $paymentItemMetaRepository,
        private SubscriptionTypeItemMetaRepository $subscriptionTypeItemMetaRepository,
        private SubscriptionTypeItemsRepository $subscriptionTypeItemsRepository,
    ) {
    }

    /**
     * @param ActiveRow $subscription
     *
     * @return array newly created requests
     * @throws MissingFamilySubscriptionTypeException
     */
    public function createFromSubscription(ActiveRow $subscription): array
    {
        $familySubscriptionType = $this->familySubscriptionTypesRepository->findByMasterSubscriptionType($subscription->subscription_type);
        if (!$familySubscriptionType) {
            throw new MissingFamilySubscriptionTypeException(
                "Unable to find FamilySubscriptionType for subscription ID [{$subscription->id}].",
            );
        }

        if ($familySubscriptionType->slave_subscription_type_id === null) {
            return $this->generateRequestFromCustomSubscription($subscription);
        }

        $requestsToGenerateCount = $this->getRequestsToGenerateCount($subscription, $familySubscriptionType);
        if ($requestsToGenerateCount === 0) {
            throw new InvalidConfigurationException(
                "Unable to load number of family requests from subscription type or payment for subscription ID [{$subscription->id}].",
            );
        }

        $requests = $this->familyRequestsRepository->masterSubscriptionFamilyRequests($subscription);
        $requestsCount = $requests->count('*');

        $newRequests = [];

        if ($requestsCount < $requestsToGenerateCount) {
            for ($i = 0; $i < $requestsToGenerateCount - $requestsCount; $i++) {
                $newRequests[] = $this->familyRequestsRepository->add($subscription, $familySubscriptionType->slave_subscription_type);
            }
        }

        return $newRequests;
    }

    public function getRequestsToGenerateCount($subscription, $familySubscriptionType): int
    {
        // load number of requests (slave subscriptions) to generate
        $requestsToGenerateCount = 0;
        if ($familySubscriptionType->count !== 0) {
            // default option; count of request should be configured in family subscription type's count field
            $requestsToGenerateCount = $familySubscriptionType->count;
        } else {
            // otherwise load it from payment item (current family subscription type)
            $payment = $this->paymentsRepository->subscriptionPayment($subscription);
            if (!$payment) {
                throw new InvalidConfigurationException(
                    "Unable to load number of family requests from subscription type or payment for subscription ID [{$subscription->id}].",
                );
            }

            // this is primarily for custom-made payments; there's no system support to enter these values
            $meta = $this->paymentMetaRepository->findByPaymentAndKey($payment, 'family_subscriptions_count');
            if ($meta) {
                $requestsToGenerateCount = (int) $meta->value;
            } else {
                $paymentItems = $this->paymentsRepository->getPaymentItemsByType($payment, SubscriptionTypePaymentItem::TYPE);
                foreach ($paymentItems as $paymentItem) {
                    if ($paymentItem->subscription_type->code === $familySubscriptionType->master_subscription_type->code) {
                        $requestsToGenerateCount = $paymentItem->count;
                        break;
                    }
                }
            }
        }

        return $requestsToGenerateCount;
    }

    public function activeFamilyOwners(): Selection
    {
        $now = new DateTime();
        return $this->subscriptionsRepository->getTable()
            ->where('subscription_type_id IN (?)', $this->familySubscriptionTypesRepository->masterSubscriptionTypes())
            ->where('start_time <= ? ', $now)
            ->where('end_time > ? ', $now);
    }


    /**
     * Number of active family subscriptions - parents only.
     *
     * @param bool $allowCached
     * @param bool $forceCacheUpdate
     *
     * @return int
     */
    public function activeFamilyOwnersCount(bool $allowCached = false, bool $forceCacheUpdate = false): int
    {
        $callable = function () {
            return $this->activeFamilyOwners()
                ->count("DISTINCT(subscriptions.user_id)");
        };

        if ($allowCached) {
            return $this->cacheRepository->loadAndUpdate(
                'active_family_owners_count',
                $callable,
                DateTime::from('-1 hour'),
                $forceCacheUpdate,
            );
        }

        return $callable();
    }

    /**
     * Number of all generated family requests for currently active family subscriptions.
     */
    public function activeFamilyRequestsCount(bool $allowCached = false, bool $forceCacheUpdate = false): int
    {
        $callable = function () {
            return $this->familyRequestsRepository->getTable()
                ->where('master_subscription_id IN (?)', $this->activeFamilyOwners())
                ->where('status IN (?)', [FamilyRequestsRepository::STATUS_CREATED, FamilyRequestsRepository::STATUS_ACCEPTED])
                ->count("*");
        };

        if ($allowCached) {
            return $this->cacheRepository->loadAndUpdate(
                'active_family_requests_count',
                $callable,
                DateTime::from('-1 hour'),
                $forceCacheUpdate,
            );
        }

        return $callable();
    }

    /**
     * Number of all current paying subscribers and all current generated family requests.
     */
    public function activePaidSubscribersWithFamilyRequestsCount(bool $allowCached = false, bool $forceCacheUpdate = false): int
    {
        $callable = function () {
            // get paying subscribers
            // but remove parent subscriptions without access to content
            // company/family children are included in paying subscribers
            $paidSubscribers = $this->paymentsRepository->paidSubscribers();
            $masterSubscriptionTypes = $this->familySubscriptionTypesRepository->masterSubscriptionTypes();
            if (!empty($masterSubscriptionTypes)) {
                $paidSubscribers->where('subscriptions.subscription_type_id NOT IN (?)', $masterSubscriptionTypes);
            }
            $paidSubscribersCount = $paidSubscribers->count('DISTINCT(subscriptions.user_id)');

            // include unused children subscriptions; these are already paid
            $unusedRequests = $this->familyRequestsRepository->getTable()
                ->where('status', FamilyRequestsRepository::STATUS_CREATED)
                ->where('master_subscription_id IN (?)', $this->activeFamilyOwners())
                ->count('*');

            return ($paidSubscribersCount + $unusedRequests);
        };

        if ($allowCached) {
            return $this->cacheRepository->loadAndUpdate(
                'active_paid_subscribers_with_family_requests_count',
                $callable,
                DateTime::from('-1 hour'),
                $forceCacheUpdate,
            );
        }

        return $callable();
    }

    public function createConfigurableFamilySubscriptionPaymentItemContainer(
        PaymentItemsConfig $paymentItemsConfig,
    ): PaymentItemContainer {
        $paymentItemContainer = new PaymentItemContainer();

        foreach ($paymentItemsConfig->getItemsConfig() as $itemConfig) {
            $subscriptionTypeItemMeta = $this->subscriptionTypeItemMetaRepository
                ->findBySubscriptionTypeItemAndKey($itemConfig->subscriptionTypeItem, 'family_slave_subscription_type_id')
                ->fetch();
            if (!$subscriptionTypeItemMeta) {
                throw new \Exception("No family slave subscription types associated to subscription type item: {$itemConfig->subscriptionTypeItem->id}");
            }

            $slaveSubscriptionType = $this->subscriptionTypesRepository->find($subscriptionTypeItemMeta->value);
            if (!$slaveSubscriptionType) {
                throw new \Exception("No slave subscription type found with ID: {$subscriptionTypeItemMeta->value}");
            }

            // load meta from subscription type item & merge with new information
            $slaveSubscriptionTypeItems = $this->subscriptionTypeItemsRepository->getItemsForSubscriptionType($slaveSubscriptionType)->fetchAll();
            if (count($slaveSubscriptionTypeItems) > 1) {
                throw new \Exception("There should be only one subscription type item for " .
                    "child subscription type [ID: {$slaveSubscriptionType->id}] of configurable family/company subscription [ID: {$itemConfig->subscriptionTypeItem->subcription_type_id}]. " .
                    "Otherwise number of payment items won't match number of configurable subscription type items.");
            }
            $slaveSubscriptionTypeItem = reset($slaveSubscriptionTypeItems);
            $metas = $this->subscriptionTypeItemMetaRepository
                ->findBySubscriptionTypeItem($slaveSubscriptionTypeItem)
                ->fetchPairs('key', 'value');

            $metas = [
                ...$metas,
                ...$itemConfig->meta,
                FamilyRequests::PAYMENT_ITEM_META_SLAVE_SUBSCRIPTION_TYPE_ID => $slaveSubscriptionType->id,
            ];

            $subscriptionTypePaymentItem = SubscriptionTypePaymentItem::fromSubscriptionTypeItem($itemConfig->subscriptionTypeItem, $itemConfig->count)
                ->forceMeta($metas);

            if ($itemConfig->name) {
                $subscriptionTypePaymentItem->forceName($itemConfig->name);
            }

            if ($itemConfig->price) {
                $subscriptionTypePaymentItem->forcePrice($itemConfig->price);
            }

            if ($itemConfig->vat) {
                $subscriptionTypePaymentItem->forceVat($itemConfig->vat);
            }

            if ($itemConfig->noVat) {
                $subscriptionTypePaymentItem->forcePrice(
                    $subscriptionTypePaymentItem->unitPriceWithoutVAT(),
                );
                $subscriptionTypePaymentItem->forceVat(0);
                $paymentItemContainer->setPreventOssVatChange();
            }
            $paymentItemContainer->addItem($subscriptionTypePaymentItem);
        }

        return $paymentItemContainer;
    }

    private function generateRequestFromCustomSubscription(ActiveRow $subscription): array
    {
        $payment = $this->paymentsRepository->subscriptionPayment($subscription);
        if (!$payment) {
            throw new InvalidConfigurationException("Unable to find payment for subscription ID [{$subscription->id}].");
        }

        $paymentItems = $this->paymentsRepository->getPaymentItemsByType($payment, SubscriptionTypePaymentItem::TYPE);
        if (count($paymentItems) === 0) {
            throw new InvalidConfigurationException("No payment items associated for payment ID [{$payment->id}].");
        }

        $newRequests = [];
        foreach ($paymentItems as $paymentItem) {
            $slaveSubscriptionTypeIdMeta = $this->paymentItemMetaRepository->findByPaymentItemAndKey(
                $paymentItem,
                self::PAYMENT_ITEM_META_SLAVE_SUBSCRIPTION_TYPE_ID,
            )->fetch();
            if ($slaveSubscriptionTypeIdMeta) {
                $slaveSubscriptionTypeId = $slaveSubscriptionTypeIdMeta->value;
            } else {
                // @deprecated option, used only for backward compatibility
                $slaveSubscriptionTypeId = $paymentItem->subscription_type_id;
            }

            if ($slaveSubscriptionTypeId === $payment->subscription_type_id) {
                // this should never happen but check nevertheless
                throw new \RuntimeException("Payment subscription type ID [{$payment->subscription_type_id}] is the same as slave subscription type ID - there is probably an error in master/slave family subscription type definition.");
            }

            $slaveSubscriptionType = $this->subscriptionTypesRepository->find($slaveSubscriptionTypeId);
            if (!$slaveSubscriptionType) {
                throw new InvalidConfigurationException("Unable to load slave subscription from payment item [{$paymentItem->name}] of payment [{$payment->id}].");
            }

            for ($i = 0; $i < $paymentItem->count; $i++) {
                $newRequests[] = $this->familyRequestsRepository->add($subscription, $slaveSubscriptionType);
            }
        }

        return $newRequests;
    }
}
