<?php

namespace Crm\FamilyModule\Tests;

use Crm\FamilyModule\Models\Extension\ExtendFamilyExtension;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Builder\SubscriptionTypeBuilder;

/**
 * @property SubscriptionTypeBuilder $subscriptionTypeBuilder
 * @property FamilySubscriptionTypesRepository $familySubscriptionTypesRepository
 */
trait SeedFamilySubscriptionTypesTrait
{
    public function seedFamilySubscriptionTypes($daysLength = 31, $familySubscriptionsCount = 5, $masterSubscriptionTypeCode = 'master_subscription'): array
    {
        $masterSubscriptionType = $this->subscriptionTypeBuilder
            ->createNew()
            ->setName($masterSubscriptionTypeCode)
            ->setCode($masterSubscriptionTypeCode)
            ->setUserLabel('')
            ->setActive(true)
            ->setPrice(1)
            ->setLength($daysLength)
            ->setExtensionMethod(ExtendFamilyExtension::METHOD_CODE)
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
            ->setExtensionMethod(ExtendFamilyExtension::METHOD_CODE)
            ->setContentAccessOption('web', 'mobile')
            ->save();

        $this->familySubscriptionTypesRepository->add($masterSubscriptionType, $slaveSubscriptionType, 'copy', $familySubscriptionsCount);

        return [$masterSubscriptionType, $slaveSubscriptionType];
    }
}
