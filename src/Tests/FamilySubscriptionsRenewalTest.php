<?php

namespace Crm\FamilyModule\Tests;

use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
use Crm\FamilyModule\Events\FamilyRequestAcceptedEvent;
use Crm\FamilyModule\Events\FamilyRequestActivationSyncHandler;
use Crm\FamilyModule\Events\FamilyRequestCanceledEvent;
use Crm\FamilyModule\Events\FamilyRequestDeactivationSyncHandler;
use Crm\FamilyModule\Events\NewSubscriptionHandler;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\PaymentStatusChangeHandler;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentMethodsRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\PaymentsModule\Tests\Gateways\TestRecurrentGateway;
use Crm\SubscriptionsModule\Events\NewSubscriptionEvent;
use Crm\SubscriptionsModule\Models\Generator\SubscriptionsGenerator;
use Crm\SubscriptionsModule\Models\Generator\SubscriptionsParams;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repositories\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Repositories\UsersRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class FamilySubscriptionsRenewalTest extends BaseTestCase
{
    private UserManager $userManager;
    private UsersRepository $usersRepository;
    private SubscriptionsGenerator $subscriptionGenerator;
    private PaymentsRepository $paymentsRepository;
    private FamilyRequestsRepository $familyRequestsRepository;
    private ActiveRow $paymentGateway;
    private LazyEventEmitter $lazyEventEmitter;
    private FamilyRequests $familyRequest;
    private DonateSubscription $donateSubscription;
    private SubscriptionsRepository $subscriptionsRepository;
    private RecurrentPaymentsRepository $recurrentPaymentsRepository;
    private PaymentMethodsRepository $paymentMethodsRepository;
    private SubscriptionMetaRepository $subscriptionMetaRepository;

    protected function requiredRepositories(): array
    {
        return [
            ...parent::requiredRepositories(),
            PaymentMethodsRepository::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->lazyEventEmitter = $this->inject(LazyEventEmitter::class);
        $this->userManager = $this->inject(UserManager::class);
        $this->usersRepository = $this->inject(UsersRepository::class);
        $this->subscriptionsRepository = $this->inject(SubscriptionsRepository::class);
        $this->subscriptionGenerator = $this->inject(SubscriptionsGenerator::class);
        $this->paymentsRepository = $this->inject(PaymentsRepository::class);
        $this->recurrentPaymentsRepository = $this->inject(RecurrentPaymentsRepository::class);
        $this->paymentMethodsRepository = $this->inject(PaymentMethodsRepository::class);
        $this->familyRequestsRepository = $this->inject(FamilyRequestsRepository::class);
        $this->subscriptionMetaRepository = $this->inject(SubscriptionMetaRepository::class);
        $this->familyRequest = $this->inject(FamilyRequests::class);
        $this->donateSubscription = $this->inject(DonateSubscription::class);

        /** @var PaymentGatewaysRepository $pgr */
        $pgr = $this->getRepository(PaymentGatewaysRepository::class);
        $this->paymentGateway = $pgr->findByCode(TestRecurrentGateway::GATEWAY_CODE);

        // To create subscriptions from payments, register listener
        $this->lazyEventEmitter->addListener(PaymentChangeStatusEvent::class, $this->inject(PaymentStatusChangeHandler::class));

        // To create family requests and renew family subscriptions
        $this->lazyEventEmitter->addListener(NewSubscriptionEvent::class, $this->inject(NewSubscriptionHandler::class));

        // To sync family requests to next subscription
        $this->lazyEventEmitter->addListener(FamilyRequestAcceptedEvent::class, $this->inject(FamilyRequestActivationSyncHandler::class));
        $this->lazyEventEmitter->addListener(FamilyRequestCanceledEvent::class, $this->inject(FamilyRequestDeactivationSyncHandler::class));
    }

    protected function tearDown(): void
    {
        $this->lazyEventEmitter->removeAllListeners(PaymentChangeStatusEvent::class);
        $this->lazyEventEmitter->removeAllListeners(NewSubscriptionEvent::class);
        $this->lazyEventEmitter->removeAllListeners(FamilyRequestAcceptedEvent::class);
        $this->lazyEventEmitter->removeAllListeners(FamilyRequestCanceledEvent::class);

        parent::tearDown();
    }

    public function testConsecutiveNonRecurrentFamilyRenewal()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser1 = $this->userWithRegDate('slave1@example.com');
        $slaveUser2 = $this->userWithRegDate('slave2@example.com');

        // Generate master subscription for previous month + handler generates family requests
        $previousSubscriptions = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            new DateTime('now - 30 days'),
            new DateTime('now + 1 days'),
            true,
        ), 1);

        // Grab one of the requests (there should be 5 of them)
        $previousFamilyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($previousSubscriptions[0])->fetchAll();
        $this->assertCount(5, $previousFamilyRequests);

        // Donate to slave 1
        $this->donateSubscription->connectFamilyUser($slaveUser1, current($previousFamilyRequests));
        // Save old request for another user
        $unusedOldRequest = next($previousFamilyRequests);

        // Generate consecutive master subscription (simulates customer who paid for next month 2 days before subscription ended without recurrent payment)
        $nextSubscriptions = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            new DateTime('now + 1 days'),
            new DateTime('now + 31 days'),
            true,
        ), 1);

        // Check family subscriptions were connected
        $nextFamilySubscriptionId = $this->subscriptionMetaRepository->getMeta($previousSubscriptions[0], FamilyRequests::NEXT_FAMILY_SUBSCRIPTION_META)->fetch();
        $this->assertEquals($nextSubscriptions[0]->id, $nextFamilySubscriptionId->value);

        // Check slave 1 was renewed as well
        $this->assertEquals(2, $this->subscriptionsRepository->userSubscriptions($slaveUser1)->count());

        // Active old request for slave 2
        $this->donateSubscription->connectFamilyUser($slaveUser2, $unusedOldRequest);

        // Check this activate old (tied to old request) and new subscription (by using unused request for new subscription for slave 2
        $this->assertEquals(2, $this->subscriptionsRepository->userSubscriptions($slaveUser2)->count());

        // Check counts of unused family requests
        $this->assertEquals(3, $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($previousSubscriptions[0])->count());
        $this->assertEquals(3, $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($nextSubscriptions[0])->count());
    }

    public function testRecurrentFamilyRenewal()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        // Create user, pay, donate subscriptions to slave users
        $masterUser = $this->userWithRegDate('master@example.com');
        $payment = $this->makePayment($masterUser, $masterSubscriptionType, 'now - 30 days', 'now - 30 days', new PaymentItemContainer());

        $subscription = $payment->subscription;
        $this->familyRequest->createFromSubscription($subscription);

        $slaveUser1 = $this->userWithRegDate('slave1@example.com');
        $slaveUser2 = $this->userWithRegDate('slave2@example.com');
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionFamilyRequests($subscription)
            ->where(['status' => FamilyRequestsRepository::STATUS_CREATED])->fetchAll();

        // Assert slave users received subscriptions
        $this->assertCount(5, $familyRequests);
        $this->donateSubscription->connectFamilyUser($slaveUser1, current($familyRequests));
        $this->donateSubscription->connectFamilyUser($slaveUser2, next($familyRequests));

        $this->assertEquals(1, $this->subscriptionsRepository->userSubscriptions($slaveUser1)->count());
        $this->assertEquals(1, $this->subscriptionsRepository->userSubscriptions($slaveUser2)->count());

        // Create recurrent payment
        $this->makeRecurrentPayment($masterUser, $payment, $masterSubscriptionType, new DateTime('now'));

        // Check that subscription is renewed + slave subscriptions are renewed
        $this->assertEquals(2, $this->subscriptionsRepository->userSubscriptions($masterUser)->count());
        $this->assertEquals(2, $this->subscriptionsRepository->userSubscriptions($slaveUser1)->count());
        $this->assertEquals(2, $this->subscriptionsRepository->userSubscriptions($slaveUser2)->count());
    }

    public function testFamilyRenewalWithMoreSubscriptionTypesAndWithCorrectCounts()
    {
        [$masterSubscriptionType, $printSlaveSubscriptionType, $webSlaveSubscriptionType] = $this->seedFamilyCustomizableSubscriptionType();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser1 = $this->userWithRegDate('slave1@example.com');
        $slaveUser2 = $this->userWithRegDate('slave2@example.com');
        $slaveUser3 = $this->userWithRegDate('slave3@example.com');
        $slaveUser4 = $this->userWithRegDate('slave4@example.com');
        $slaveUser5 = $this->userWithRegDate('slave5@example.com');

        $paymentItemContainer = new PaymentItemContainer();
        $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
            $webSlaveSubscriptionType->id,
            $webSlaveSubscriptionType->name,
            10,
            20,
            3,
        ));
        $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
            $printSlaveSubscriptionType->id,
            $printSlaveSubscriptionType->name,
            5,
            20,
            2,
        ));

        $payment = $this->makePayment(
            $masterUser,
            $masterSubscriptionType,
            'now',
            'now',
            $paymentItemContainer,
        );

        $previousSubscription = $payment->subscription;
        $previousFamilyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($previousSubscription)->fetchAll();
        $this->assertCount(5, $previousFamilyRequests);

        // Assign randomly subscriptions
        $this->donateSubscription->connectFamilyUser($slaveUser4, current($previousFamilyRequests));
        $this->donateSubscription->connectFamilyUser($slaveUser2, next($previousFamilyRequests));
        $this->donateSubscription->connectFamilyUser($slaveUser1, next($previousFamilyRequests));
        $this->donateSubscription->connectFamilyUser($slaveUser5, next($previousFamilyRequests));
        $this->donateSubscription->connectFamilyUser($slaveUser3, next($previousFamilyRequests));

        // family requests should be accepted
        $previousFamilyRequests = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($previousSubscription)->fetchAll();
        $this->assertCount(5, $previousFamilyRequests);

        // create paymemt of family subscription with same
        $nextPayment = $this->paymentsRepository->add(
            $masterSubscriptionType,
            $this->paymentGateway,
            $masterUser,
            $paymentItemContainer,
            null,
            1,
            new DateTime(),
        );

        $this->paymentsRepository->update($nextPayment, [
            'paid_at' => new DateTime(),
            'subscription_start_at' => $previousSubscription->end_time,
        ]);
        $this->paymentsRepository->updateStatus($nextPayment, PaymentStatusEnum::Paid->value);

        $nextPayment = $this->paymentsRepository->find($nextPayment->id);
        $nextSubscription = $nextPayment->subscription;

        // Check family subscriptions were connected
        $nextFamilySubscriptionId = $this->subscriptionMetaRepository->getMeta($previousSubscription, FamilyRequests::NEXT_FAMILY_SUBSCRIPTION_META)->fetch();
        $this->assertEquals($nextSubscription->id, $nextFamilySubscriptionId->value);

        $previousSubscriptionPairs = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($previousSubscription)
            ->fetchPairs('user_id', 'subscription_type_id');

        $nextSubscriptionPairs = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($nextSubscription)
            ->fetchPairs('user_id', 'subscription_type_id');

        // users should have same subscription types as for previous request
        $this->assertEquals($previousSubscriptionPairs, $nextSubscriptionPairs);
    }

    public function testFamilyRenewalWithMoreSubscriptionTypesAndWithIncorrectCounts()
    {
        [$masterSubscriptionType, $printSlaveSubscriptionType, $webSlaveSubscriptionType] = $this->seedFamilyCustomizableSubscriptionType();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser1 = $this->userWithRegDate('slave1@example.com');
        $slaveUser2 = $this->userWithRegDate('slave2@example.com');
        $slaveUser3 = $this->userWithRegDate('slave3@example.com');
        $slaveUser4 = $this->userWithRegDate('slave4@example.com');

        $paymentItemContainer = new PaymentItemContainer();
        $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
            $webSlaveSubscriptionType->id,
            $webSlaveSubscriptionType->name,
            10,
            20,
            2,
        ));
        $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
            $printSlaveSubscriptionType->id,
            $printSlaveSubscriptionType->name,
            5,
            20,
            2,
        ));

        $firstPayment = $this->makePayment(
            $masterUser,
            $masterSubscriptionType,
            'now',
            'now',
            $paymentItemContainer,
        );

        $firstSubscription = $firstPayment->subscription;
        $firstFamilyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($firstSubscription)->fetchAll();
        $this->assertCount(4, $firstFamilyRequests);

        // Assign randomly subscriptions
        $this->donateSubscription->connectFamilyUser($slaveUser4, current($firstFamilyRequests));
        $this->donateSubscription->connectFamilyUser($slaveUser3, next($firstFamilyRequests));
        $this->donateSubscription->connectFamilyUser($slaveUser2, next($firstFamilyRequests));
        $this->donateSubscription->connectFamilyUser($slaveUser1, next($firstFamilyRequests));

        // Make next payment with bigger count of subscriptions; they should get activated
        $paymentItemContainer = new PaymentItemContainer();
        $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
            $webSlaveSubscriptionType->id,
            $webSlaveSubscriptionType->name,
            10,
            20,
            3,
        ));
        $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
            $printSlaveSubscriptionType->id,
            $printSlaveSubscriptionType->name,
            5,
            20,
            3,
        ));
        $secondPayment = $this->makePayment(
            $masterUser,
            $masterSubscriptionType,
            'now',
            $firstSubscription->end_time,
            $paymentItemContainer,
        );

        // Check family subscriptions were connected
        $nextFamilySubscriptionId = $this->subscriptionMetaRepository->getMeta($firstSubscription, FamilyRequests::NEXT_FAMILY_SUBSCRIPTION_META)->fetch()?->value;
        $this->assertEquals($secondPayment->subscription_id, $nextFamilySubscriptionId);

        $secondSubscription = $secondPayment->subscription;
        $secondFamilyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($secondSubscription);

        // three requests should be activated (copied from the first subscription), three should remain available
        $this->assertCount(4, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($secondSubscription)->fetchAll());
        $this->assertCount(2, $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($secondSubscription)->fetchAll());

        // Make next payment with different count of subscriptions
        $paymentItemContainer = new PaymentItemContainer();
        $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
            $webSlaveSubscriptionType->id,
            $webSlaveSubscriptionType->name,
            10,
            20,
            2,
        ));
        $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
            $printSlaveSubscriptionType->id,
            $printSlaveSubscriptionType->name,
            5,
            20,
            3,
        ));
        $thirdPayment = $this->makePayment(
            $masterUser,
            $masterSubscriptionType,
            'now',
            $secondSubscription->end_time,
            $paymentItemContainer,
        );

        // Check family subscriptions weren't connected
        $nextFamilySubscriptionId = $this->subscriptionMetaRepository->getMeta($secondSubscription, FamilyRequests::NEXT_FAMILY_SUBSCRIPTION_META)->fetch();
        $this->assertNull($nextFamilySubscriptionId);

        $thirdSubscription = $thirdPayment->subscription;
        $thirdFamilyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($thirdSubscription);

        // all request should be in status created
        $this->assertCount(5, $thirdFamilyRequests->where('status', FamilyRequestsRepository::STATUS_CREATED)->fetchAll());
    }

    public function testDeactivateFamilyRequestSynchronizesToNextSubscription()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser = $this->userWithRegDate('slave@example.com');

        // Create first master subscription
        $firstSubscriptions = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            new DateTime('now'),
            new DateTime('now + 31 days'),
            true,
        ), 1);
        $firstSubscription = $firstSubscriptions[0];

        // Activate family request
        $firstRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($firstSubscription)->fetchAll();
        $this->assertCount(5, $firstRequests);
        $this->donateSubscription->connectFamilyUser($slaveUser, current($firstRequests));

        // Create consecutive master subscription (auto-activates next request)
        $secondSubscriptions = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            new DateTime('now + 31 days'),
            new DateTime('now + 62 days'),
            true,
        ), 1);
        $secondSubscription = $secondSubscriptions[0];

        // Verify slave has 2 subscriptions and both requests are accepted
        $this->assertEquals(2, $this->subscriptionsRepository->userSubscriptions($slaveUser)->count());
        $this->assertEquals(1, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($firstSubscription)->count());
        $this->assertEquals(1, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($secondSubscription)->count());

        // Count unused requests before deactivation
        $firstUnusedBefore = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($firstSubscription)->count();
        $secondUnusedBefore = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($secondSubscription)->count();

        // Deactivate first request (should sync to second)
        $acceptedRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($firstSubscription)->fetch();
        $this->donateSubscription->releaseFamilyRequest($acceptedRequest);

        // Assert both requests are canceled
        $this->assertEquals(0, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($firstSubscription)->count());
        $this->assertEquals(0, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($secondSubscription)->where('slave_user_id', $slaveUser->id)->count());

        // Assert replacement request created on both subscriptions (sync handler creates replacement on next subscription too)
        $this->assertEquals($firstUnusedBefore + 1, $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($firstSubscription)->count());
        $this->assertEquals($secondUnusedBefore + 1, $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($secondSubscription)->count());
    }

    public function testDeactivateOneUserLeavesOthersActive()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser1 = $this->userWithRegDate('slave1@example.com');
        $slaveUser2 = $this->userWithRegDate('slave2@example.com');

        // Create first master subscription
        $firstSubscriptions = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            new DateTime('now'),
            new DateTime('now + 31 days'),
            true,
        ), 1);
        $firstSubscription = $firstSubscriptions[0];

        // Activate family requests for both slave users
        $firstRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($firstSubscription)->fetchAll();
        $this->donateSubscription->connectFamilyUser($slaveUser1, current($firstRequests));
        $this->donateSubscription->connectFamilyUser($slaveUser2, next($firstRequests));

        // Create consecutive master subscription
        $secondSubscriptions = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            new DateTime('now + 31 days'),
            new DateTime('now + 62 days'),
            true,
        ), 1);
        $secondSubscription = $secondSubscriptions[0];

        // Verify both slaves have 2 subscriptions and 2 accepted requests each
        $this->assertEquals(2, $this->subscriptionsRepository->userSubscriptions($slaveUser1)->count());
        $this->assertEquals(2, $this->subscriptionsRepository->userSubscriptions($slaveUser2)->count());
        $this->assertEquals(2, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($firstSubscription)->count());
        $this->assertEquals(2, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($secondSubscription)->count());

        // Deactivate only slave1's request
        $slave1Request = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($firstSubscription)->where('slave_user_id', $slaveUser1->id)->fetch();
        $this->donateSubscription->releaseFamilyRequest($slave1Request);

        // Assert slave1's requests on both subscriptions are canceled
        $this->assertEquals(0, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($firstSubscription)->where('slave_user_id', $slaveUser1->id)->count());
        $this->assertEquals(0, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($secondSubscription)->where('slave_user_id', $slaveUser1->id)->count());

        // Assert slave2's requests on both subscriptions remain active
        $this->assertEquals(1, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($firstSubscription)->where('slave_user_id', $slaveUser2->id)->count());
        $this->assertEquals(1, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($secondSubscription)->where('slave_user_id', $slaveUser2->id)->count());
    }

    public function testDeactivateWithoutNextSubscription()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser = $this->userWithRegDate('slave@example.com');

        // Create master subscription (no consecutive)
        $subscription = $this->createMasterSubscriptionWithFamilyRequests($masterUser, $masterSubscriptionType);

        // Activate family request
        $request = $this->activateFamilyRequestForUser($subscription, $slaveUser);

        // Verify request is accepted
        $this->assertFamilyRequestCounts($subscription, expectedAccepted: 1, expectedUnused: 4);

        // Verify no next subscription metadata exists
        $nextSubMeta = $this->subscriptionMetaRepository->getMeta($subscription, FamilyRequests::NEXT_FAMILY_SUBSCRIPTION_META)->fetch();
        $this->assertNull($nextSubMeta);

        // Deactivate family request (should not throw error)
        $request = $this->familyRequestsRepository->find($request->id);
        $this->donateSubscription->releaseFamilyRequest($request);

        // Assert request is canceled and replacement created
        $request = $this->familyRequestsRepository->find($request->id);
        $this->assertEquals(FamilyRequestsRepository::STATUS_CANCELED, $request->status);
        $this->assertFamilyRequestCounts($subscription, expectedAccepted: 0, expectedUnused: 5, expectedCanceled: 1);
    }

    public function testCancelAfterRecurrentChargeCreatesReplacementsOnBothSubscriptions()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser = $this->userWithRegDate('slave@example.com');

        // Create initial payment and subscription
        $payment = $this->makePayment($masterUser, $masterSubscriptionType, 'now - 26 days', 'now - 26 days', new PaymentItemContainer());
        $currentSubscription = $payment->subscription;

        // Create family requests
        $this->familyRequest->createFromSubscription($currentSubscription);

        // Activate one family request
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($currentSubscription)->fetchAll();
        $this->assertCount(5, $familyRequests);
        $this->donateSubscription->connectFamilyUser($slaveUser, current($familyRequests));

        // Verify: 1 accepted, 4 unused = 5 total on current subscription
        $this->assertEquals(1, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($currentSubscription)->count());
        $this->assertEquals(4, $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($currentSubscription)->count());

        // Charge recurrent payment (creates next subscription and syncs activation)
        $nextPayment = $this->makeRecurrentPayment($masterUser, $payment, $masterSubscriptionType, 'now');
        $nextSubscription = $nextPayment->subscription;

        // Verify: activation was synced to next subscription
        $this->assertEquals(1, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($nextSubscription)->count());
        $this->assertEquals(4, $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($nextSubscription)->count());

        // Cancel the child subscription on current subscription
        $acceptedRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($currentSubscription)->fetch();
        $this->donateSubscription->releaseFamilyRequest($acceptedRequest);

        // Assert current subscription: 0 accepted, 5 unused (4 original + 1 replacement)
        $this->assertEquals(0, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($currentSubscription)->count());
        $this->assertEquals(5, $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($currentSubscription)->count());
        $this->assertEquals(1, $this->familyRequestsRepository->masterSubscriptionCanceledFamilyRequests($currentSubscription)->count());

        // Assert next subscription: 0 accepted, 5 unused (4 original + 1 replacement from sync)
        $this->assertEquals(0, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($nextSubscription)->count());
        $this->assertEquals(5, $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($nextSubscription)->count());
        $this->assertEquals(1, $this->familyRequestsRepository->masterSubscriptionCanceledFamilyRequests($nextSubscription)->count());
    }

    public function testDeactivationWhenRequestAlreadyCanceledOnNext()
    {
        $masterSubscriptionType = $this->seedFamilySubscriptionTypes()[0];

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser = $this->userWithRegDate('slave@example.com');

        // Create initial payment and subscription
        $payment = $this->makePayment($masterUser, $masterSubscriptionType, 'now - 26 days', 'now - 26 days', new PaymentItemContainer());
        $currentSubscription = $payment->subscription;

        // Create family requests and activate one
        $this->familyRequest->createFromSubscription($currentSubscription);
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($currentSubscription)->fetchAll();
        $this->donateSubscription->connectFamilyUser($slaveUser, current($familyRequests));

        // Verify: 1 accepted, 4 unused
        $this->assertFamilyRequestCounts($currentSubscription, expectedAccepted: 1, expectedUnused: 4);

        // Charge recurrent payment (creates next subscription and syncs activation)
        $nextPayment = $this->makeRecurrentPayment($masterUser, $payment, $masterSubscriptionType, 'now');
        $nextSubscription = $nextPayment->subscription;

        // Verify: activation synced to next subscription
        $this->assertFamilyRequestCounts($nextSubscription, expectedAccepted: 1, expectedUnused: 4);

        // Manually cancel the request on NEXT subscription first (simulates already canceled)
        $nextRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($nextSubscription)->fetch();
        $this->donateSubscription->releaseFamilyRequest($nextRequest);

        // Verify next subscription now has: 0 accepted, 5 unused, 1 canceled
        $this->assertFamilyRequestCounts($nextSubscription, expectedAccepted: 0, expectedUnused: 5, expectedCanceled: 1);

        // Now cancel the request on current subscription (triggers sync to already-canceled next request)
        $currentRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($currentSubscription)->fetch();
        $unusedBeforeOnNext = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($nextSubscription)->count();

        // This should NOT fail, and should NOT create duplicate replacement on next
        $this->donateSubscription->releaseFamilyRequest($currentRequest);

        // Assert current subscription: 0 accepted, 5 unused (4 + 1 replacement)
        $this->assertFamilyRequestCounts($currentSubscription, expectedAccepted: 0, expectedUnused: 5, expectedCanceled: 1);

        // Assert next subscription: unchanged (early return prevents duplicate replacement)
        $this->assertEquals($unusedBeforeOnNext, $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($nextSubscription)->count());
        $this->assertFamilyRequestCounts($nextSubscription, expectedAccepted: 0, expectedUnused: 5, expectedCanceled: 1);
    }

    public function testDeactivationWhenSlaveSubscriptionAlreadyStopped()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser = $this->userWithRegDate('slave@example.com');

        // Create initial payment and subscription
        $payment = $this->makePayment($masterUser, $masterSubscriptionType, 'now - 26 days', 'now - 26 days', new PaymentItemContainer());
        $currentSubscription = $payment->subscription;

        // Create family requests and activate one
        $this->familyRequest->createFromSubscription($currentSubscription);
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($currentSubscription)->fetchAll();
        $this->donateSubscription->connectFamilyUser($slaveUser, current($familyRequests));

        // Verify: 1 accepted, 4 unused
        $this->assertFamilyRequestCounts($currentSubscription, expectedAccepted: 1, expectedUnused: 4);

        // Charge recurrent payment (creates next subscription and syncs activation)
        $nextPayment = $this->makeRecurrentPayment($masterUser, $payment, $masterSubscriptionType, 'now');
        $nextSubscription = $nextPayment->subscription;

        // Verify: activation synced to next subscription
        $this->assertFamilyRequestCounts($nextSubscription, expectedAccepted: 1, expectedUnused: 4);

        // Manually stop the slave subscription on NEXT subscription (end_time in the past)
        $nextRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($nextSubscription)->fetch();
        $slaveSubscription = $nextRequest->slave_subscription;
        $this->subscriptionsRepository->update($slaveSubscription, [
            'end_time' => new DateTime('now - 1 hour'),
        ]);

        // Verify slave subscription is stopped
        $slaveSubscription = $this->subscriptionsRepository->find($slaveSubscription->id);
        $this->assertLessThan(new DateTime(), $slaveSubscription->end_time);

        // Now cancel the request on current subscription (triggers sync to next with already-stopped subscription)
        $currentRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($currentSubscription)->fetch();

        // This should NOT fail - releaseFamilyRequest checks end_time >= now() before stopping
        $this->donateSubscription->releaseFamilyRequest($currentRequest);

        // Assert current subscription: 0 accepted, 5 unused (4 + 1 replacement)
        $this->assertFamilyRequestCounts($currentSubscription, expectedAccepted: 0, expectedUnused: 5, expectedCanceled: 1);

        // Assert next subscription: 0 accepted, 5 unused (4 + 1 replacement from sync)
        $this->assertFamilyRequestCounts($nextSubscription, expectedAccepted: 0, expectedUnused: 5, expectedCanceled: 1);

        // Verify slave subscription end_time unchanged (already stopped, not stopped again)
        $slaveSubscriptionAfter = $this->subscriptionsRepository->find($slaveSubscription->id);
        $this->assertEquals($slaveSubscription->end_time, $slaveSubscriptionAfter->end_time);
    }

    public function testCancelNextSubscriptionDoesNotSyncToPrevious()
    {
        $masterSubscriptionType = $this->seedFamilySubscriptionTypes()[0];

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser = $this->userWithRegDate('slave@example.com');

        // Create first master subscription
        $firstSubscriptions = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            new DateTime('now'),
            new DateTime('now + 31 days'),
            true,
        ), 1);
        $firstSubscription = $firstSubscriptions[0];

        // Activate family request on first subscription
        $firstRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($firstSubscription)->fetchAll();
        $this->donateSubscription->connectFamilyUser($slaveUser, current($firstRequests));

        // Create consecutive master subscription (auto-activates next request)
        $secondSubscriptions = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            new DateTime('now + 31 days'),
            new DateTime('now + 62 days'),
            true,
        ), 1);
        $secondSubscription = $secondSubscriptions[0];

        // Verify both requests are accepted
        $this->assertFamilyRequestCounts($firstSubscription, expectedAccepted: 1, expectedUnused: 4);
        $this->assertFamilyRequestCounts($secondSubscription, expectedAccepted: 1, expectedUnused: 4);

        // Cancel request on SECOND subscription (should NOT sync backward to first)
        $secondRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($secondSubscription)->fetch();
        $this->donateSubscription->releaseFamilyRequest($secondRequest);

        // Assert second subscription request is canceled
        $this->assertFamilyRequestCounts($secondSubscription, expectedAccepted: 0, expectedUnused: 5, expectedCanceled: 1);

        // Assert first subscription request remains ACTIVE (no backward sync)
        $this->assertFamilyRequestCounts($firstSubscription, expectedAccepted: 1, expectedUnused: 4, expectedCanceled: 0);
    }

    public function testDeactivationSyncsThroughMultipleSubscriptions()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser = $this->userWithRegDate('slave@example.com');

        // Create first subscription via payment flow
        $firstPayment = $this->makePayment($masterUser, $masterSubscriptionType, 'now - 26 days', 'now - 26 days', new PaymentItemContainer());
        $firstSubscription = $firstPayment->subscription;

        // Create family requests and activate one
        $this->familyRequest->createFromSubscription($firstSubscription);
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($firstSubscription)->fetchAll();
        $this->donateSubscription->connectFamilyUser($slaveUser, current($familyRequests));

        // Verify first subscription: 1 accepted, 4 unused
        $this->assertFamilyRequestCounts($firstSubscription, expectedAccepted: 1, expectedUnused: 4);

        // Create second consecutive subscription via recurrent payment (A → B)
        $secondPayment = $this->makeRecurrentPayment($masterUser, $firstPayment, $masterSubscriptionType, 'now');
        $secondSubscription = $secondPayment->subscription;

        // Verify second subscription: activation synced (1 accepted, 4 unused)
        $this->assertFamilyRequestCounts($secondSubscription, expectedAccepted: 1, expectedUnused: 4);

        // Create third consecutive subscription via recurrent payment (B → C)
        // This simulates edge case where user has accidental purchase creating chain
        $thirdPayment = $this->makeRecurrentPayment($masterUser, $secondPayment, $masterSubscriptionType, 'now');
        $thirdSubscription = $thirdPayment->subscription;

        // Verify third subscription: activation synced (1 accepted, 4 unused)
        $this->assertFamilyRequestCounts($thirdSubscription, expectedAccepted: 1, expectedUnused: 4);

        // Deactivate on FIRST subscription - should cascade to second AND third
        $firstRequest = $this->familyRequestsRepository
            ->masterSubscriptionAcceptedFamilyRequests($firstSubscription)
            ->fetch();
        $this->donateSubscription->releaseFamilyRequest($firstRequest);

        // Assert ALL THREE subscriptions have canceled request + replacement
        $this->assertFamilyRequestCounts($firstSubscription, expectedAccepted: 0, expectedUnused: 5, expectedCanceled: 1);
        $this->assertFamilyRequestCounts($secondSubscription, expectedAccepted: 0, expectedUnused: 5, expectedCanceled: 1);
        $this->assertFamilyRequestCounts($thirdSubscription, expectedAccepted: 0, expectedUnused: 5, expectedCanceled: 1);
    }

    public function testActivationDoesNotDuplicateAlreadyAcceptedRequest()
    {
        $masterSubscriptionType = $this->seedFamilySubscriptionTypes()[0];

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser = $this->userWithRegDate('slave@example.com');

        // Create initial payment and subscription
        $payment = $this->makePayment($masterUser, $masterSubscriptionType, 'now - 26 days', 'now - 26 days', new PaymentItemContainer());
        $currentSubscription = $payment->subscription;

        // Create family requests and activate one
        $this->familyRequest->createFromSubscription($currentSubscription);
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($currentSubscription)->fetchAll();
        $this->donateSubscription->connectFamilyUser($slaveUser, current($familyRequests));

        // Verify: 1 accepted, 4 unused
        $this->assertFamilyRequestCounts($currentSubscription, expectedAccepted: 1, expectedUnused: 4);

        // Charge recurrent payment (creates next subscription and syncs activation)
        $nextPayment = $this->makeRecurrentPayment($masterUser, $payment, $masterSubscriptionType, 'now');
        $nextSubscription = $nextPayment->subscription;

        // Verify: activation synced to next subscription (1 accepted)
        $this->assertFamilyRequestCounts($nextSubscription, expectedAccepted: 1, expectedUnused: 4);

        // Try to manually activate the already-accepted request on next subscription
        $acceptedRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($nextSubscription)->fetch();
        $result = $this->donateSubscription->connectFamilyUser($slaveUser, $acceptedRequest);

        // Should return error because user already has subscription from this master
        $this->assertEquals(DonateSubscription::ERROR_IN_USE, $result);

        // Verify counts unchanged (no duplicate activation)
        $this->assertFamilyRequestCounts($nextSubscription, expectedAccepted: 1, expectedUnused: 4);

        // Verify slave user still has exactly 2 subscriptions (not 3)
        $this->assertEquals(2, $this->subscriptionsRepository->userSubscriptions($slaveUser)->count());
    }

    public function testNoteSyncsDuringRenewalActivation()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser = $this->userWithRegDate('slave@example.com');

        $payment = $this->makePayment($masterUser, $masterSubscriptionType, 'now - 26 days', 'now - 26 days', new PaymentItemContainer());
        $currentSubscription = $payment->subscription;

        $this->familyRequest->createFromSubscription($currentSubscription);
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($currentSubscription)->fetchAll();
        $request = current($familyRequests);
        $this->familyRequestsRepository->update($request, ['note' => 'Test note for slave user']);
        $this->donateSubscription->connectFamilyUser($slaveUser, $request);

        $nextPayment = $this->makeRecurrentPayment($masterUser, $payment, $masterSubscriptionType, 'now');
        $nextSubscription = $nextPayment->subscription;

        $nextRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($nextSubscription)->fetch();
        $this->assertEquals('Test note for slave user', $nextRequest->note);
    }

    public function testNoteSyncsDuringManualActivation()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser = $this->userWithRegDate('slave@example.com');

        $firstSubscription = $this->createMasterSubscriptionWithFamilyRequests(
            $masterUser,
            $masterSubscriptionType,
            'now',
            'now + 31 days',
        );
        $secondSubscription = $this->createMasterSubscriptionWithFamilyRequests(
            $masterUser,
            $masterSubscriptionType,
            'now + 31 days',
            'now + 62 days',
        );

        $firstRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($firstSubscription)->fetchAll();
        $request = current($firstRequests);
        $this->familyRequestsRepository->update($request, ['note' => 'Manual activation note']);
        $this->donateSubscription->connectFamilyUser($slaveUser, $request);

        $secondRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($secondSubscription)->fetch();
        $this->assertEquals('Manual activation note', $secondRequest->note);
    }

    public function testNoteSyncsWhenEdited()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser = $this->userWithRegDate('slave@example.com');

        $payment = $this->makePayment($masterUser, $masterSubscriptionType, 'now - 26 days', 'now - 26 days', new PaymentItemContainer());
        $currentSubscription = $payment->subscription;

        $this->familyRequest->createFromSubscription($currentSubscription);
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($currentSubscription)->fetchAll();
        $this->donateSubscription->connectFamilyUser($slaveUser, current($familyRequests));

        $nextPayment = $this->makeRecurrentPayment($masterUser, $payment, $masterSubscriptionType, 'now');
        $nextSubscription = $nextPayment->subscription;

        $currentRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($currentSubscription)->fetch();
        $this->familyRequestsRepository->update($currentRequest, ['note' => 'Edited note']);
        $this->donateSubscription->syncNoteToNextSubscription(
            $this->familyRequestsRepository->find($currentRequest->id),
        );

        $nextRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($nextSubscription)->fetch();
        $this->assertEquals('Edited note', $nextRequest->note);
    }

    public function testNoteSyncsThroughMultipleSubscriptions()
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterUser = $this->userWithRegDate('master@example.com');
        $slaveUser = $this->userWithRegDate('slave@example.com');

        $firstPayment = $this->makePayment($masterUser, $masterSubscriptionType, 'now - 26 days', 'now - 26 days', new PaymentItemContainer());
        $firstSubscription = $firstPayment->subscription;

        $this->familyRequest->createFromSubscription($firstSubscription);
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($firstSubscription)->fetchAll();
        $this->donateSubscription->connectFamilyUser($slaveUser, current($familyRequests));

        $secondPayment = $this->makeRecurrentPayment($masterUser, $firstPayment, $masterSubscriptionType, 'now');
        $secondSubscription = $secondPayment->subscription;

        $thirdPayment = $this->makeRecurrentPayment($masterUser, $secondPayment, $masterSubscriptionType, 'now');
        $thirdSubscription = $thirdPayment->subscription;

        $firstRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($firstSubscription)->fetch();
        $this->familyRequestsRepository->update($firstRequest, ['note' => 'Recursive sync note']);
        $this->donateSubscription->syncNoteToNextSubscription(
            $this->familyRequestsRepository->find($firstRequest->id),
        );

        $secondRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($secondSubscription)->fetch();
        $thirdRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($thirdSubscription)->fetch();
        $this->assertEquals('Recursive sync note', $secondRequest->note);
        $this->assertEquals('Recursive sync note', $thirdRequest->note);
    }

    private function makePayment(
        ActiveRow $user,
        ActiveRow $subscriptionType,
        string $paidAtString,
        string $startSubscriptionAtString,
        PaymentItemContainer $paymentItemContainer,
    ) {
        $payment = $this->paymentsRepository->add(
            $subscriptionType,
            $this->paymentGateway,
            $user,
            $paymentItemContainer,
            null,
            1,
            new DateTime($startSubscriptionAtString),
        );
        $this->paymentsRepository->update($payment, ['paid_at' => new DateTime($paidAtString)]);
        $this->paymentsRepository->updateStatus($payment, PaymentStatusEnum::Paid->value);
        return $this->paymentsRepository->find($payment->id);
    }

    private function makeRecurrentPayment($user, $previousPayment, $subscriptionType, $paidAtString)
    {
        $paymentMethod = $this->paymentMethodsRepository->findOrAdd(
            $user->id,
            $previousPayment->payment_gateway_id,
            '1111',
        );
        $recurrent = $this->recurrentPaymentsRepository->add($paymentMethod, $previousPayment, new DateTime('now - 1 minute'), 1, 1);

        $payment = $this->paymentsRepository->add(
            $subscriptionType,
            $this->paymentGateway,
            $user,
            new PaymentItemContainer(),
            null,
            1,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            true,
        );

        $this->recurrentPaymentsRepository->update($recurrent, [
            'payment_id' => $payment->id,
        ]);

        $this->paymentsRepository->update($payment, ['paid_at' => new DateTime($paidAtString)]);
        $this->paymentsRepository->updateStatus($payment, PaymentStatusEnum::Paid->value);

        $this->recurrentPaymentsRepository->setCharged($recurrent, $payment, 'OK', 'OK');

        return $this->paymentsRepository->find($payment->id);
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

    private function createMasterSubscriptionWithFamilyRequests(
        ActiveRow $masterUser,
        ActiveRow $masterSubscriptionType,
        string $startDate = 'now',
        string $endDate = 'now + 31 days',
    ): ActiveRow {
        $subscriptions = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            new DateTime($startDate),
            new DateTime($endDate),
            true,
        ), 1);
        return $subscriptions[0];
    }

    private function activateFamilyRequestForUser(ActiveRow $subscription, ActiveRow $slaveUser): ActiveRow
    {
        $requests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($subscription)->fetchAll();
        $request = current($requests);
        $this->donateSubscription->connectFamilyUser($slaveUser, $request);
        return $request;
    }

    private function assertFamilyRequestCounts(
        ActiveRow $subscription,
        int $expectedAccepted,
        int $expectedUnused,
        int $expectedCanceled = 0,
    ): void {
        $this->assertEquals(
            $expectedAccepted,
            $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($subscription)->count(),
            "Expected {$expectedAccepted} accepted family requests on subscription #{$subscription->id}",
        );
        $this->assertEquals(
            $expectedUnused,
            $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($subscription)->count(),
            "Expected {$expectedUnused} unused family requests on subscription #{$subscription->id}",
        );
        if ($expectedCanceled > 0) {
            $this->assertEquals(
                $expectedCanceled,
                $this->familyRequestsRepository->masterSubscriptionCanceledFamilyRequests($subscription)->count(),
                "Expected {$expectedCanceled} canceled family requests on subscription #{$subscription->id}",
            );
        }
    }
}
