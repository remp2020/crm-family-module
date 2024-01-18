<?php

namespace Crm\FamilyModule\Tests;

use Crm\FamilyModule\Events\NewSubscriptionHandler;
use Crm\FamilyModule\Events\SubscriptionUpdatedHandler;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\SubscriptionsModule\Events\NewSubscriptionEvent;
use Crm\SubscriptionsModule\Events\SubscriptionUpdatedEvent;
use Crm\SubscriptionsModule\Models\Generator\SubscriptionsGenerator;
use Crm\SubscriptionsModule\Models\Generator\SubscriptionsParams;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UsersModule\Models\Auth\UserManager;
use League\Event\Emitter;
use Nette\Utils\DateTime;

class UpdateFamilyRequestSubscriptionsTest extends BaseTestCase
{
    private const COMPANY_SUBSCRIPTIONS_LENGTH = 31;

    private const COMPANY_SUBSCRIPTIONS_COUNT = 5;

    /** @var UserManager */
    private $userManager;

    /** @var SubscriptionsGenerator */
    private $subscriptionGenerator;

    /** @var SubscriptionsRepository */
    private $subscriptionsRepository;

    /** @var FamilyRequestsRepository */
    private $familyRequestsRepository;

    /** @var DonateSubscription */
    private $donateSubscription;

    /** @var Emitter */
    private $emitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userManager = $this->inject(UserManager::class);
        $this->subscriptionGenerator = $this->inject(SubscriptionsGenerator::class);
        $this->subscriptionsRepository = $this->inject(SubscriptionsRepository::class);
        $this->familyRequestsRepository = $this->inject(FamilyRequestsRepository::class);
        $this->donateSubscription = $this->inject(DonateSubscription::class);
        $this->emitter = $this->inject(Emitter::class);

        // To create family requests and renew family subscriptions
        $this->emitter->addListener(NewSubscriptionEvent::class, $this->inject(NewSubscriptionHandler::class));


        // To create family requests and renew family subscriptions
        $this->emitter->addListener(SubscriptionUpdatedEvent::class, $this->inject(SubscriptionUpdatedHandler::class));
    }

    public function testUpdateSlaveSubscriptionTypeStartAndEndTime()
    {
        [$masterSubscription] = $this->prepareFamilyRequests();

        $accepted = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($masterSubscription)->fetchAll();
        $accepted1 = reset($accepted);
        $accepted2 = next($accepted);

        $this->assertEquals($masterSubscription->start_time, $accepted1->slave_subscription->start_time);
        $this->assertEquals($masterSubscription->end_time, $accepted1->slave_subscription->end_time);

        $newStartTime = new DateTime('today - 100 days');
        $newEndTime = new DateTime('today + 100 days');

        $this->subscriptionsRepository->update($masterSubscription, [
            'start_time' => $newStartTime,
            'end_time' => $newEndTime
        ]);

        $slaveSubscription = $this->subscriptionsRepository->find($accepted1->slave_subscription->id);

        $this->assertEquals($masterSubscription->start_time, $slaveSubscription->start_time);
        $this->assertEquals($masterSubscription->end_time, $slaveSubscription->end_time);

        $slaveSubscription2 = $this->subscriptionsRepository->find($accepted2->slave_subscription->id);

        $this->assertEquals($masterSubscription->start_time, $slaveSubscription2->start_time);
        $this->assertEquals($masterSubscription->end_time, $slaveSubscription2->end_time);
    }

    public function testNotUpdatingCanceledFamilyRequestSubscriptions()
    {
        [$masterSubscription] = $this->prepareFamilyRequests();

        $canceled = $this->familyRequestsRepository->masterSubscriptionCanceledFamilyRequests($masterSubscription)->fetch();
        $canceledRequestStartTime = $canceled->slave_subscription->start_time;
        $canceledRequestEndTime = $canceled->slave_subscription->end_time;

        $this->subscriptionsRepository->update($masterSubscription, [
            'start_time' => new DateTime('today - 100 days'),
            'end_time' => new DateTime('today + 100 days'),
        ]);

        $canceledRequest = $this->familyRequestsRepository->find($canceled->id);

        $this->assertEquals($canceledRequest->slave_subscription->start_time, $canceledRequestStartTime);
        $this->assertEquals($canceledRequest->slave_subscription->end_time, $canceledRequestEndTime);
    }

    public function testDoNotUpdateFamilyRequestWithFamilySubscriptionTypeDonationMethodDifferentThanCopy()
    {
        [$masterSubscription, $masterSubscriptionType] = $this->prepareFamilyRequests();

        $familySubscriptionType = $this->familySubscriptionTypesRepository->findByMasterSubscriptionType($masterSubscriptionType);
        $this->familySubscriptionTypesRepository->update($familySubscriptionType, [
            'donation_method' => 'custom'
        ]);

        $accepted = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($masterSubscription)->fetchAll();
        $accepted = reset($accepted);
        $acceptedRequestStartTime = $accepted->slave_subscription->start_time;
        $acceptedRequestEndTime = $accepted->slave_subscription->end_time;

        $this->subscriptionsRepository->update($masterSubscription, [
            'start_time' => new DateTime('today - 100 days'),
            'end_time' => new DateTime('today + 100 days'),
        ]);

        $acceptedRequest = $this->familyRequestsRepository->find($accepted->id);
        $this->assertEquals($acceptedRequest->slave_subscription->start_time, $acceptedRequestStartTime);
        $this->assertEquals($acceptedRequest->slave_subscription->end_time, $acceptedRequestEndTime);
    }

    private function prepareFamilyRequests(): array
    {
        [$masterSubscriptionType, $slaveSubscriptionType] = $this->seedFamilySubscriptionTypes(
            self::COMPANY_SUBSCRIPTIONS_LENGTH,
            self::COMPANY_SUBSCRIPTIONS_COUNT
        );

        $masterUser = $this->createUser('master@example.com');
        $slaveUserWithAccepted = $this->createUser('slave_with_accepted@example.com');
        $slaveUserWithAccepted2 = $this->createUser('slave_with_accepted2@example.com');
        $slaveUserWithCanceled1 = $this->createUser('slave_with_canceled2@example.com');

        // generate master subscription + handler generates family requests
        $masterSubscription = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            new DateTime('2020-07-01'),
            new DateTime('2020-08-01'),
            true
        ), 1);

        // check number of generated requests
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($masterSubscription[0])->fetchAll();
        $this->assertCount(self::COMPANY_SUBSCRIPTIONS_COUNT, $familyRequests);

        // donate one subscription to slave user & reload change
        $acceptedFamilyRequest = reset($familyRequests);
        $this->donateSubscription->setNow(new \DateTime('2020-07-10'));
        $this->donateSubscription->connectFamilyUser(
            $slaveUserWithAccepted,
            $acceptedFamilyRequest
        );

        $acceptedFamilyRequest2 = next($familyRequests);
        $this->donateSubscription->setNow(new \DateTime('2020-07-11'));
        $this->donateSubscription->connectFamilyUser(
            $slaveUserWithAccepted2,
            $acceptedFamilyRequest2
        );

        $canceledFamilyRequest1 = next($familyRequests);
        $this->donateSubscription->setNow(new \DateTime('2020-07-10'));
        $this->donateSubscription->connectFamilyUser(
            $slaveUserWithCanceled1,
            $canceledFamilyRequest1
        );
        $canceledFamilyRequest1 = $this->familyRequestsRepository->find($canceledFamilyRequest1->id);
        $this->donateSubscription->releaseFamilyRequest($canceledFamilyRequest1);

        return [$masterSubscription[0], $masterSubscriptionType, $slaveSubscriptionType];
    }

    private function createUser($email)
    {
        return $this->userManager->addNewUser(
            $email,
            false,
            'unknown',
            null,
            false
        );
    }
}
