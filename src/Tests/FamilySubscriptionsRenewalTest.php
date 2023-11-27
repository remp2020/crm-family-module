<?php

namespace Crm\FamilyModule\Tests;

use Crm\ApplicationModule\Event\LazyEventEmitter;
use Crm\FamilyModule\Events\NewSubscriptionHandler;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\PaymentStatusChangeHandler;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Events\NewSubscriptionEvent;
use Crm\SubscriptionsModule\Generator\SubscriptionsGenerator;
use Crm\SubscriptionsModule\Generator\SubscriptionsParams;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Repository\UsersRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class FamilySubscriptionsRenewalTest extends BaseTestCase
{
    /** @var UserManager */
    private $userManager;

    /** @var UsersRepository */
    private $usersRepository;

    /** @var SubscriptionsGenerator */
    private $subscriptionGenerator;

    /** @var PaymentsRepository */
    private $paymentsRepository;

    /** @var FamilyRequestsRepository */
    private $familyRequestsRepository;

    /** @var PaymentGatewaysRepository */
    private $paymentGateway;

    /** @var LazyEventEmitter */
    private $lazyEventEmitter;

    /** @var FamilyRequests */
    private $familyRequest;

    /** @var DonateSubscription */
    private $donateSubscription;

    /** @var SubscriptionsRepository */
    private $subscriptionsRepository;

    /** @var RecurrentPaymentsRepository */
    private $recurrentPaymentsRepository;

    /** @var SubscriptionMetaRepository */
    private $subscriptionMetaRepository;

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
        $this->familyRequestsRepository = $this->inject(FamilyRequestsRepository::class);
        $this->subscriptionMetaRepository = $this->inject(SubscriptionMetaRepository::class);
        $this->familyRequest = $this->inject(FamilyRequests::class);
        $this->donateSubscription = $this->inject(DonateSubscription::class);

        /** @var PaymentGatewaysRepository $pgr */
        $pgr = $this->getRepository(PaymentGatewaysRepository::class);
        $this->paymentGateway = $pgr->add('test', 'test', 10, true, true);

        // To create subscriptions from payments, register listener
        $this->lazyEventEmitter->addListener(PaymentChangeStatusEvent::class, $this->inject(PaymentStatusChangeHandler::class));

        // To create family requests and renew family subscriptions
        $this->lazyEventEmitter->addListener(NewSubscriptionEvent::class, $this->inject(NewSubscriptionHandler::class));
    }

    protected function tearDown(): void
    {
        $this->lazyEventEmitter->removeAllListeners(PaymentChangeStatusEvent::class);
        $this->lazyEventEmitter->removeAllListeners(NewSubscriptionEvent::class);

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
            true
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
            true
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
            3
        ));
        $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
            $printSlaveSubscriptionType->id,
            $printSlaveSubscriptionType->name,
            5,
            20,
            2
        ));

        $payment = $this->makePayment(
            $masterUser,
            $masterSubscriptionType,
            'now',
            'now',
            $paymentItemContainer
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
            new DateTime()
        );

        $this->paymentsRepository->update($nextPayment, [
            'paid_at' => new DateTime(),
            'subscription_start_at' => $previousSubscription->end_time,
        ]);
        $this->paymentsRepository->updateStatus($nextPayment, PaymentsRepository::STATUS_PAID);

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
            2
        ));
        $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
            $printSlaveSubscriptionType->id,
            $printSlaveSubscriptionType->name,
            5,
            20,
            2
        ));

        $firstPayment = $this->makePayment(
            $masterUser,
            $masterSubscriptionType,
            'now',
            'now',
            $paymentItemContainer
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
            3
        ));
        $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
            $printSlaveSubscriptionType->id,
            $printSlaveSubscriptionType->name,
            5,
            20,
            3
        ));
        $secondPayment = $this->makePayment(
            $masterUser,
            $masterSubscriptionType,
            'now',
            $firstSubscription->end_time,
            $paymentItemContainer
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
            2
        ));
        $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
            $printSlaveSubscriptionType->id,
            $printSlaveSubscriptionType->name,
            5,
            20,
            3
        ));
        $thirdPayment = $this->makePayment(
            $masterUser,
            $masterSubscriptionType,
            'now',
            $secondSubscription->end_time,
            $paymentItemContainer
        );

        // Check family subscriptions weren't connected
        $nextFamilySubscriptionId = $this->subscriptionMetaRepository->getMeta($secondSubscription, FamilyRequests::NEXT_FAMILY_SUBSCRIPTION_META)->fetch();
        $this->assertNull($nextFamilySubscriptionId);

        $thirdSubscription = $thirdPayment->subscription;
        $thirdFamilyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($thirdSubscription);

        // all request should be in status created
        $this->assertCount(5, $thirdFamilyRequests->where('status', FamilyRequestsRepository::STATUS_CREATED)->fetchAll());
    }

    private function makePayment(
        ActiveRow $user,
        ActiveRow $subscriptionType,
        string $paidAtString,
        string $startSubscriptionAtString,
        PaymentItemContainer $paymentItemContainer
    ) {
        $payment = $this->paymentsRepository->add(
            $subscriptionType,
            $this->paymentGateway,
            $user,
            $paymentItemContainer,
            null,
            1,
            new DateTime($startSubscriptionAtString)
        );
        $this->paymentsRepository->update($payment, ['paid_at' => new DateTime($paidAtString)]);
        $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PAID);
        return $this->paymentsRepository->find($payment->id);
    }

    private function makeRecurrentPayment($user, $previousPayment, $subscriptionType, $paidAtString)
    {
        $recurrent = $this->recurrentPaymentsRepository->add('1111', $previousPayment, new DateTime('now - 1 minute'), 1, 1);

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
            true
        );

        $this->recurrentPaymentsRepository->update($recurrent, [
            'payment_id' => $payment->id,
        ]);

        $this->paymentsRepository->update($payment, ['paid_at' => new DateTime($paidAtString)]);
        $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PAID);

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
}
