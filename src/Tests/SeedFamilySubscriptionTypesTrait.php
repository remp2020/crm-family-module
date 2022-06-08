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

    public function seedFamilyCustomizableSubscriptionType()
    {
        $slavePrintSubscriptionType = $this->subscriptionTypeBuilder
            ->createNew()
            ->setName('print_slave')
            ->setCode('print_slave')
            ->setUserLabel('')
            ->setActive(true)
            ->setPrice(0)
            ->setLength(0)
            ->setExtensionMethod(ExtendFamilyExtension::METHOD_CODE)
            ->setContentAccessOption('print')
            ->save();

        $slaveWebSubscriptionType = $this->subscriptionTypeBuilder
            ->createNew()
            ->setName('print_slave')
            ->setCode('print_slave')
            ->setUserLabel('')
            ->setActive(true)
            ->setPrice(0)
            ->setLength(0)
            ->setExtensionMethod(ExtendFamilyExtension::METHOD_CODE)
            ->setContentAccessOption('web')
            ->save();

        $masterSubscriptionType = $this->subscriptionTypeBuilder
            ->createNew()
            ->setName('customizable_parent')
            ->setCode('slave_subscription')
            ->setUserLabel('')
            ->setActive(true)
            ->setPrice(0)
            ->addSubscriptionTypeItem('Print', 0, 20, ['family_slave_subscription_type_id' => $slavePrintSubscriptionType])
            ->addSubscriptionTypeItem('Web', 0, 20, ['family_slave_subscription_type_id' => $slaveWebSubscriptionType])
            ->setLength(0)
            ->setExtensionMethod(ExtendFamilyExtension::METHOD_CODE)
            ->setContentAccessOption('no_content')
            ->save();

        $this->familySubscriptionTypesRepository->add($masterSubscriptionType, null, 'copy', 0);

        return [$masterSubscriptionType, $slavePrintSubscriptionType, $slaveWebSubscriptionType];
    }
}
