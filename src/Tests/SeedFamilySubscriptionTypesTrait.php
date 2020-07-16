<?php

namespace Crm\FamilyModule\Tests;

use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Builder\SubscriptionTypeBuilder;

/**
 * @property SubscriptionTypeBuilder $subscriptionTypeBuilder
 * @property FamilySubscriptionTypesRepository $familySubscriptionTypesRepository
 */
trait SeedFamilySubscriptionTypesTrait
{
    public function seedFamilySubscriptionTypes($daysLength = 31, $familySubscriptionsCount = 5): array
    {
        $masterSubscriptionType = $this->subscriptionTypeBuilder
            ->createNew()
            ->setName('master_subscription')
            ->setCode('master_subscription')
            ->setUserLabel('')
            ->setActive(true)
            ->setPrice(1)
            ->setLength($daysLength)
            ->setContentAccessOption('web', 'mobile')
            ->save();

        $slaveSubscriptionType = $this->subscriptionTypeBuilder
            ->createNew()
            ->setName('slave_subscription')
            ->setCode('slave_subscription')
            ->setUserLabel('')
            ->setActive(true)
            ->setPrice(0)
            ->setLength($daysLength)
            ->setContentAccessOption('web', 'mobile')
            ->save();

        $this->familySubscriptionTypesRepository->add($masterSubscriptionType, $slaveSubscriptionType, 'copy', $familySubscriptionsCount);

        return [$masterSubscriptionType, $slaveSubscriptionType];
    }
}
