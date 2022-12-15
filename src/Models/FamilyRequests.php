<?php

namespace Crm\FamilyModule\Models;

use Crm\ApplicationModule\Cache\CacheRepository;
use Crm\ApplicationModule\Selection;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class FamilyRequests
{
    public const NEXT_FAMILY_SUBSCRIPTION_META = 'next_family_subscription_id';

    public const KEEP_REQUESTS_UNACTIVATED_PAYMENT_META = 'keep_requests_unactivated';

    private CacheRepository $cacheRepository;

    private FamilyRequestsRepository $familyRequestsRepository;

    private PaymentsRepository $paymentsRepository;

    private SubscriptionsRepository $subscriptionsRepository;

    private FamilySubscriptionTypesRepository $familySubscriptionTypesRepository;

    private PaymentMetaRepository $paymentMetaRepository;

    public function __construct(
        CacheRepository $cacheRepository,
        FamilyRequestsRepository $familyRequestsRepository,
        PaymentsRepository $paymentsRepository,
        SubscriptionsRepository $subscriptionsRepository,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        PaymentMetaRepository $paymentMetaRepository
    ) {
        $this->cacheRepository = $cacheRepository;
        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
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
                "Unable to find FamilySubscriptionType for subscription ID [{$subscription->id}]."
            );
        }

        if ($familySubscriptionType->slave_subscription_type_id === null) {
            return $this->generateRequestFromCustomSubscription($subscription);
        }

        $requestsToGenerateCount = $this->getRequestsToGenerateCount($subscription, $familySubscriptionType);
        if ($requestsToGenerateCount === 0) {
            throw new InvalidConfigurationException(
                "Unable to load number of family requests from subscription type or payment for subscription ID [{$subscription->id}]."
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
                    "Unable to load number of family requests from subscription type or payment for subscription ID [{$subscription->id}]."
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
                ->group('user_id')
                ->count();
        };

        if ($allowCached) {
            return $this->cacheRepository->loadAndUpdate(
                'active_family_owners_count',
                $callable,
                \Nette\Utils\DateTime::from('-1 hour'),
                $forceCacheUpdate
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
                ->count();
        };

        if ($allowCached) {
            return $this->cacheRepository->loadAndUpdate(
                'active_family_requests_count',
                $callable,
                DateTime::from('-1 hour'),
                $forceCacheUpdate
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
            // company/family children are not within this number; they don't have payment linked to subscription
            $paidSubscribers = $this->paymentsRepository->paidSubscribers();
            $masterSubscriptionTypes = $this->familySubscriptionTypesRepository->masterSubscriptionTypes();
            if (!empty($masterSubscriptionTypes)) {
                $paidSubscribers->where('subscriptions.subscription_type_id NOT IN (?)', $masterSubscriptionTypes);
            }
            $paidSubscribersCount = $paidSubscribers->count('DISTINCT(subscriptions.user_id)');

            // get family requests (company/family children)
            $familyRequestsCount = $this->activeFamilyRequestsCount();

            return ($paidSubscribersCount + $familyRequestsCount);
        };

        if ($allowCached) {
            return $this->cacheRepository->loadAndUpdate(
                'active_paid_subscribers_with_family_requests_count',
                $callable,
                DateTime::from('-1 hour'),
                $forceCacheUpdate
            );
        }

        return $callable();
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
            $count = $paymentItem->count;
            $slaveSubscriptionType = $paymentItem->subscription_type;
            if (!$slaveSubscriptionType) {
                throw new InvalidConfigurationException("Unable to load slave subscription from payment item ID [{$paymentItem->id}].");
            }

            for ($i = 0; $i < $count; $i++) {
                $newRequests[] = $this->familyRequestsRepository->add($subscription, $slaveSubscriptionType);
            }
        }

        return $newRequests;
    }
}
