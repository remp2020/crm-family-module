<?php

namespace Crm\FamilyModule\Tests;

use Crm\FamilyModule\Events\NewSubscriptionHandler;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionsRepository;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\PaymentStatusChangeHandler;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Events\NewSubscriptionEvent;
use Crm\SubscriptionsModule\Generator\SubscriptionsGenerator;
use Crm\SubscriptionsModule\Generator\SubscriptionsParams;
use Crm\SubscriptionsModule\Repository\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Repository\UsersRepository;
use League\Event\Emitter;
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

    /** @var PaymentMetaRepository */
    private $paymentMetaRepository;

    /** @var FamilyRequestsRepository */
    private $familyRequestsRepository;

    /** @var PaymentGatewaysRepository */
    private $paymentGateway;

    /** @var Emitter */
    private $emitter;

    /** @var FamilySubscriptionsRepository */
    private $familySubscriptionsRepository;

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
        $this->emitter = $this->inject(Emitter::class);
        $this->userManager = $this->inject(UserManager::class);
        $this->usersRepository = $this->inject(UsersRepository::class);
        $this->subscriptionsRepository = $this->inject(SubscriptionsRepository::class);
        $this->subscriptionGenerator = $this->inject(SubscriptionsGenerator::class);
        $this->paymentsRepository = $this->inject(PaymentsRepository::class);
        $this->paymentMetaRepository = $this->inject(PaymentMetaRepository::class);
        $this->recurrentPaymentsRepository = $this->inject(RecurrentPaymentsRepository::class);
        $this->familyRequestsRepository = $this->inject(FamilyRequestsRepository::class);
        $this->familySubscriptionsRepository = $this->inject(FamilySubscriptionsRepository::class);
        $this->subscriptionMetaRepository = $this->inject(SubscriptionMetaRepository::class);
        $this->familyRequest = $this->inject(FamilyRequests::class);
        $this->donateSubscription = $this->inject(DonateSubscription::class);

        /** @var PaymentGatewaysRepository $pgr */
        $pgr = $this->getRepository(PaymentGatewaysRepository::class);
        $this->paymentGateway = $pgr->add('test', 'test', 10, true, true);

        // To create subscriptions from payments, register listener
        $this->emitter->addListener(PaymentChangeStatusEvent::class, $this->inject(PaymentStatusChangeHandler::class));

        // To create family requests and renew family subscriptions
        $this->emitter->addListener(NewSubscriptionEvent::class, $this->inject(NewSubscriptionHandler::class));
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
        $payment = $this->makePayment($masterUser, $masterSubscriptionType, 'now - 30 days', 'now - 30 days');

        $subscription = $payment->subscription;
        $this->familyRequest->createFromSubscription($subscription);

        $slaveUser1 = $this->userWithRegDate('slave1@example.com');
        $slaveUser2 = $this->userWithRegDate('slave2@example.com');
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionFamilyRequest($subscription)
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

    private function makePayment($user, $subscriptionType, $paidAtString, $startSubscriptionAtString)
    {
        $payment = $this->paymentsRepository->add(
            $subscriptionType,
            $this->paymentGateway,
            $user,
            new PaymentItemContainer(),
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
