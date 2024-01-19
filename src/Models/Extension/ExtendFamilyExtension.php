<?php

namespace Crm\FamilyModule\Models\Extension;

use Crm\ApplicationModule\Models\NowTrait;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Models\Extension\Extension;
use Crm\SubscriptionsModule\Models\Extension\ExtensionInterface;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Nette\Database\Table\ActiveRow;

/**
 * Extends:
 * - first subscription of company/family subscription type;
 * - or starts immediately.
 */
class ExtendFamilyExtension implements ExtensionInterface
{
    use NowTrait;

    public const METHOD_CODE = 'extend_family';
    public const METHOD_NAME = 'Extend family';

    public function __construct(
        private SubscriptionsRepository $subscriptionsRepository,
        private FamilySubscriptionTypesRepository $familySubscriptionTypesRepository
    ) {
    }

    public function getStartTime(ActiveRow $user, ActiveRow $subscriptionType): Extension
    {
        // load IDs of all family subscription types
        $familySubscriptionTypeIds = array_merge(
            $this->familySubscriptionTypesRepository->masterSubscriptionTypes(),
            $this->familySubscriptionTypesRepository->slaveSubscriptionTypes()
        );

        $userFamilySubscription = $this->subscriptionsRepository->userSubscriptions($user->id)
            ->where('end_time > ?', $this->getNow())
            ->where('subscription_type_id IN ?', $familySubscriptionTypeIds)
            ->fetch();

        // if user doesn't have family subscription, start now
        if (!$userFamilySubscription) {
            return new Extension($this->getNow());
        }

        return new Extension($userFamilySubscription->end_time, true);
    }
}
