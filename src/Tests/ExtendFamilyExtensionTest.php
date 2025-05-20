<?php

namespace Crm\FamilyModule\Tests;

use Crm\SubscriptionsModule\Models\Extension\ExtendActualExtension;
use Crm\SubscriptionsModule\Models\Generator\SubscriptionsGenerator;
use Crm\SubscriptionsModule\Models\Generator\SubscriptionsParams;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UsersModule\Models\Auth\UserManager;
use Nette\Utils\DateTime;

class ExtendFamilyExtensionTest extends BaseTestCase
{
    /** @var UserManager */
    private $userManager;

    /** @var SubscriptionsGenerator */
    private $subscriptionGenerator;

    /** @var SubscriptionsRepository */
    private $subscriptionsRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userManager = $this->inject(UserManager::class);
        $this->subscriptionGenerator = $this->inject(SubscriptionsGenerator::class);
        $this->subscriptionsRepository = $this->inject(SubscriptionsRepository::class);
    }

    public function testFamilyTypeAfterFamilySubscriptionType()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $user = $this->createUser('user@example.com');
        // Generate family subscription
        $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $user,
            'regular',
            new DateTime('now - 15 days'),
            null,
            true,
        ), 1);
        $currentSubscription = $this->subscriptionsRepository->actualUserSubscription($user);

        // load start date for next family subscription type
        $extensionStartDate = $this->subscriptionsRepository->getSubscriptionExtension($masterSubscriptionType, $user);

        // generated start date should be same as end date of previous family subscription
        $this->assertEquals($currentSubscription->end_time, $extensionStartDate->getDate());
    }

    public function testFamilyTypeAfterNonFamilySubscriptionType()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();
        $nonFamilySubscriptionType = $this->seedNonFamilySubscriptionType();

        $user = $this->createUser('user@example.com');
        // Generate non family subscription
        $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $nonFamilySubscriptionType,
            $user,
            'regular',
            new DateTime('now - 15 days'),
            null,
            true,
        ), 1);
        $currentSubscription = $this->subscriptionsRepository->actualUserSubscription($user);

        // load start date for next family subscription type
        $nowDate = new DateTime();
        $extensionStartDate = $this->subscriptionsRepository->getSubscriptionExtension($masterSubscriptionType, $user);

        // generated start date should be NOW; family subscription types ignore all other subscription types
        // and start immediately if no other family subscription is present
        $this->assertLessThan($currentSubscription->end_time, $extensionStartDate->getDate());
        // unable to check NOW because seconds can be different; checking dates should be enough
        $this->assertEquals($nowDate->format('Y-m-d H:i'), $extensionStartDate->getDate()->format('Y-m-d H:i'));
    }

    public function testFamilyTypeAfterFamilyAndNonFamilySubscriptionTypes()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();
        $nonFamilySubscriptionType = $this->seedNonFamilySubscriptionType();

        $user = $this->createUser('user@example.com');
        // Generate family subscription
        $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $user,
            'regular',
            new DateTime('now - 15 days'),
            null,
            true,
        ), 1);

        // Generate next non family subscription starting now
        $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $nonFamilySubscriptionType,
            $user,
            'regular',
            new DateTime('now'),
            null,
            true,
        ), 1);

        $currentSubscriptions = $this->subscriptionsRepository->actualUserSubscriptions($user);
        $this->assertEquals(2, $currentSubscriptions->count('*'));

        // load start date for next family subscription type
        $extensionStartDate = $this->subscriptionsRepository->getSubscriptionExtension($masterSubscriptionType, $user);

        // generated start date should be same as end date of previous family subscription; ignoring non family subscription
        $currentFamilySubscription = $currentSubscriptions->where(['subscription_type.code' => $masterSubscriptionType->code])->fetch();
        $this->assertEquals($currentFamilySubscription->end_time, $extensionStartDate->getDate());
    }

    private function createUser($email)
    {
        return $this->userManager->addNewUser($email, false, 'unknown', null, false);
    }

    private function seedNonFamilySubscriptionType()
    {
        return $this->subscriptionTypeBuilder
            ->createNew()
            ->setName('non_family_subscription')
            ->setCode('non_family_subscription')
            ->setUserLabel('')
            ->setActive(true)
            ->setPrice(1)
            ->setLength(31)
            ->setExtensionMethod(ExtendActualExtension::METHOD_CODE)
            ->setContentAccessOption('web', 'mobile')
            ->save();
    }
}
