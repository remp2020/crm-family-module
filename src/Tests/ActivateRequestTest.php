<?php

namespace Crm\FamilyModule\Tests;

use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
use Crm\FamilyModule\Events\NewSubscriptionHandler;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\SubscriptionsModule\Events\NewSubscriptionEvent;
use Crm\SubscriptionsModule\Models\Generator\SubscriptionsGenerator;
use Crm\SubscriptionsModule\Models\Generator\SubscriptionsParams;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Repositories\UsersRepository;
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

    /** @var LazyEventEmitter */
    private $lazyEventEmitter;

    /** @var FamilyRequests */
    private $familyRequest;

    /** @var DonateSubscription */
    private $donateSubscription;

    /** @var SubscriptionsRepository */
    private $subscriptionsRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lazyEventEmitter = $this->inject(LazyEventEmitter::class);
        $this->userManager = $this->inject(UserManager::class);
        $this->usersRepository = $this->inject(UsersRepository::class);
        $this->subscriptionsRepository = $this->inject(SubscriptionsRepository::class);
        $this->subscriptionGenerator = $this->inject(SubscriptionsGenerator::class);
        $this->familyRequestsRepository = $this->inject(FamilyRequestsRepository::class);
        $this->familyRequest = $this->inject(FamilyRequests::class);
        $this->donateSubscription = $this->inject(DonateSubscription::class);

        // To create family requests and renew family subscriptions
        $this->lazyEventEmitter->addListener(
            NewSubscriptionEvent::class,
            $this->inject(NewSubscriptionHandler::class),
        );
    }

    protected function tearDown(): void
    {
        $this->lazyEventEmitter->removeAllListeners(NewSubscriptionEvent::class);

        parent::tearDown();
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
            true,
        ), 1);

        // Grab one of the requests (there should be 5 of them)
        $requests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($subscriptions[0])->fetchAll();
        $request = current($requests);

        // Donate to slave 1
        $this->assertEquals(DonateSubscription::ERROR_MASTER_SUBSCRIPTION_EXPIRED, $this->donateSubscription->connectFamilyUser($slaveUser1, $request));
    }

    public function testActivateAlreadyActivatedRequest()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser1 = $this->userWithRegDate('slave1@example.com');

        // Generate master subscription for previous month + handler generates family requests
        $subscriptions = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            new DateTime('now'),
            new DateTime('now + 1 day'),
            true,
        ), 1);

        // Grab one of the requests (there should be 5 of them)
        $requests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($subscriptions[0])->fetchAll();
        $request = current($requests);

        // Update state to wrong one
        $this->familyRequestsRepository->cancelCreatedRequests($masterUser);
        $request = $this->familyRequestsRepository->find($request->id);

        $this->assertEquals(DonateSubscription::ERROR_REQUEST_WRONG_STATUS, $this->donateSubscription->connectFamilyUser($slaveUser1, $request));
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
