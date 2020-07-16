<?php

namespace Crm\FamilyModule\Models;

use Crm\ApplicationModule\Cache\CacheRepository;
use Crm\ApplicationModule\Selection;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Nette\Database\Table\IRow;
use Nette\Utils\DateTime;

class FamilyRequests
{
    const NEXT_FAMILY_SUBSCRIPTION_META = 'next_family_subscription_id';

    private $cacheRepository;

    private $familyRequestsRepository;

    private $paymentsRepository;

    private $subscriptionsRepository;

    private $familySubscriptionTypesRepository;

    public function __construct(
        CacheRepository $cacheRepository,
        FamilyRequestsRepository $familyRequestsRepository,
        PaymentsRepository $paymentsRepository,
        SubscriptionsRepository $subscriptionsRepository,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository
    ) {
        $this->cacheRepository = $cacheRepository;
        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
    }

    /**
     * @param IRow $subscription
     *
     * @return array newly created requests
     * @throws MissingFamilySubscriptionTypeException
     */
    public function createFromSubscription(IRow $subscription): array
    {
        $masterSubscriptionType = $this->familySubscriptionTypesRepository->findByMasterSubscriptionType($subscription->subscription_type);
        if (!$masterSubscriptionType) {
            throw new MissingFamilySubscriptionTypeException(
                "Unable to find FamilySubscriptionType for subscription ID [{$subscription->id}]."
            );
        }

        $requests = $this->familyRequestsRepository->masterSubscriptionFamilyRequest($subscription);
        $requestsCount = $requests->count('*');

        $newRequests = [];

        if ($requestsCount < $masterSubscriptionType->count) {
            for ($i = 0; $i < $masterSubscriptionType->count - $requestsCount; $i++) {
                $newRequests[] = $this->familyRequestsRepository->add($subscription, $masterSubscriptionType->slave_subscription_type);
            }
        }

        return $newRequests;
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
}
