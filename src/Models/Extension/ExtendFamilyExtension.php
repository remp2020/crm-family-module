<?php

namespace Crm\FamilyModule\Models\Extension;

use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Extension\Extension;
use Crm\SubscriptionsModule\Extension\ExtensionInterface;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class ExtendFamilyExtension implements ExtensionInterface
{
    public const METHOD_CODE = 'extend_family';
    public const METHOD_NAME = 'Extend family';

    private $familySubscriptionTypesRepository;

    private $subscriptionsRepository;

    public function __construct(
        SubscriptionsRepository $subscriptionsRepository,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository
    ) {
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
    }

    public function getStartTime(ActiveRow $user, ActiveRow $subscriptionType)
    {
        // load IDs of all family subscription types
        $familySubscriptionTypeIds = array_merge(
            $this->familySubscriptionTypesRepository->masterSubscriptionTypes(),
            $this->familySubscriptionTypesRepository->slaveSubscriptionTypes()
        );

        $userFamilySubscription = $this->subscriptionsRepository->userSubscriptions($user->id)
            ->where('end_time > NOW()')
            ->where('subscription_type_id IN ?', $familySubscriptionTypeIds)
            ->fetch();

        // if user doesn't have family subscription, start now
        if (!$userFamilySubscription) {
            return new Extension(new DateTime());
        }

        return new Extension($userFamilySubscription->end_time, true);
    }
}
