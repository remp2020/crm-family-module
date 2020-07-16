<?php

namespace Crm\FamilyModule\Tests;

use Crm\FamilyModule\Events\NewSubscriptionHandler;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\SubscriptionsModule\Events\NewSubscriptionEvent;
use Crm\SubscriptionsModule\Generator\SubscriptionsGenerator;
use Crm\SubscriptionsModule\Generator\SubscriptionsParams;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Repository\UsersRepository;
use League\Event\Emitter;
use Nette\Utils\DateTime;

class ActivateRequestTest extends BaseTestCase
{
    /** @var UserManager */
    private $userManager;

    /** @var UsersRepository */
    private $usersRepository;

    /** @var SubscriptionsGenerator */
    private $subscriptionGenerator;

    /** @var FamilyRequestsRepository */
    private $familyRequestsRepository;

    /** @var Emitter */
    private $emitter;

    /** @var FamilyRequests */
    private $familyRequest;

    /** @var DonateSubscription */
    private $donateSubscription;

    /** @var SubscriptionsRepository */
    private $subscriptionsRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emitter = $this->inject(Emitter::class);
        $this->userManager = $this->inject(UserManager::class);
        $this->usersRepository = $this->inject(UsersRepository::class);
        $this->subscriptionsRepository = $this->inject(SubscriptionsRepository::class);
        $this->subscriptionGenerator = $this->inject(SubscriptionsGenerator::class);
        $this->familyRequestsRepository = $this->inject(FamilyRequestsRepository::class);
        $this->familyRequest = $this->inject(FamilyRequests::class);
        $this->donateSubscription = $this->inject(DonateSubscription::class);

        // To create family requests and renew family subscriptions
        $this->emitter->addListener(NewSubscriptionEvent::class, $this->inject(NewSubscriptionHandler::class));
    }

    public function testMasterSubscriptionExpired()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser1 = $this->userWithRegDate('slave1@example.com');

        // Generate master subscription for previous month + handler generates family requests
        $subscriptions = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            new DateTime('now - 31 days'),
            new DateTime('now - 1 day'), // Expired
            true
        ), 1);

        // Grab one of the requests (there should be 5 of them)
        $requests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($subscriptions[0])->fetchAll();
        $request = current($requests);

        // Donate to slave 1
        $this->assertEquals(DonateSubscription::ERROR_MASTER_SUBSCRIPTION_EXPIRED, $this->donateSubscription->connectFamilyUser($slaveUser1, $request));
    }

    private function userWithRegDate($email, $regDateString = '2020-01-01 01:00:00')
    {
        $user = $this->userManager->addNewUser($email, false, 'unknown', null, false);
        $this->usersRepository->update($user, [
            'created_at' => new DateTime($regDateString),
            'invoice' => 1,
        ]);
        return $user;
    }
}
