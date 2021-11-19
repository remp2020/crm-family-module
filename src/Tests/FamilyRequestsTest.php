<?php

namespace Crm\FamilyModule\Tests;

use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Models\InvalidConfigurationException;
use Crm\FamilyModule\Models\MissingFamilySubscriptionTypeException;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\PaymentStatusChangeHandler;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UsersModule\Auth\UserManager;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;

class FamilyRequestsTest extends BaseTestCase
{
    /** @var FamilyRequests */
    private $familyRequests;

    /** @var FamilyRequestsRepository */
    private $familyRequestsRepository;

    /** @var ActiveRow */
    private $paymentGateway;

    /** @var PaymentsRepository */
    private $paymentsRepository;

    /** @var SubscriptionsRepository */
    private $subscriptionsRepository;

    /** @var UserManager */
    private $userManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->familyRequests = $this->inject(FamilyRequests::class);
        $this->familyRequestsRepository = $this->inject(FamilyRequestsRepository::class);
        $this->paymentsRepository = $this->inject(PaymentsRepository::class);
        $this->subscriptionsRepository = $this->inject(SubscriptionsRepository::class);
        $this->userManager = $this->inject(UserManager::class);

        /** @var PaymentGatewaysRepository $pgr */
        $pgr = $this->getRepository(PaymentGatewaysRepository::class);
        $this->paymentGateway = $pgr->add('test', 'test', 10, true, true);

        $emitter = $this->inject(Emitter::class);
        // To create subscriptions from payments, register listener
        $emitter->addListener(PaymentChangeStatusEvent::class, $this->inject(PaymentStatusChangeHandler::class));
    }

    protected function tearDown(): void
    {
        $this->inject(Emitter::class)->removeListener(PaymentChangeStatusEvent::class, $this->inject(PaymentStatusChangeHandler::class));

        parent::tearDown();
    }

    public function testSuccessWithPreonfiguredCount()
    {
        $preconfiguredCount = 7;
        $paymentItemCount = 1;

        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes(
            31,
            $preconfiguredCount
        );
        $masterUser = $this->createUser('master@example.com');

        // create new payment
        $payment = $this->makePayment(
            $masterUser,
            $masterSubscriptionType,
            'now',
            'now',
            $paymentItemCount
        );
        $subscription = $payment->subscription;

        $this->familyRequests->createFromSubscription($subscription);

        // assert correct number of requests generated
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionFamilyRequests($subscription)
            ->where(['status' => FamilyRequestsRepository::STATUS_CREATED])->fetchAll();
        $this->assertCount($preconfiguredCount, $familyRequests);
    }

    public function testSuccessWithPaymentItemCount()
    {
        $preconfiguredCount = 0;
        $paymentItemCount = 7;

        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes(
            31,
            $preconfiguredCount
        );
        $masterUser = $this->createUser('master@example.com');

        // create new payment
        $payment = $this->makePayment(
            $masterUser,
            $masterSubscriptionType,
            'now',
            'now',
            $paymentItemCount
        );
        $subscription = $payment->subscription;

        $this->familyRequests->createFromSubscription($subscription);

        // assert correct number of requests generated
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionFamilyRequests($subscription)
            ->where(['status' => FamilyRequestsRepository::STATUS_CREATED])->fetchAll();
        $this->assertCount($paymentItemCount, $familyRequests);
    }

    public function testFailNoPreconfiguredNoPaymentItemCount()
    {
        $preconfiguredCount = 0;
        $paymentItemCount = 0;

        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes(
            31,
            $preconfiguredCount
        );
        $masterUser = $this->createUser('master@example.com');

        // create new payment
        $payment = $this->makePayment(
            $masterUser,
            $masterSubscriptionType,
            'now',
            'now',
            $paymentItemCount
        );
        $subscription = $payment->subscription;

        $this->expectException(InvalidConfigurationException::class);
        $this->familyRequests->createFromSubscription($subscription);

        // assert correct number of requests generated
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionFamilyRequests($subscription)
            ->where(['status' => FamilyRequestsRepository::STATUS_CREATED])->fetchAll();
        $this->assertCount(0, $familyRequests);
    }

    public function testFailMissingFamilySubscriptionTypeLink()
    {
        $preconfiguredCount = 0;
        $paymentItemCount = 0;

        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes(
            31,
            $preconfiguredCount
        );

        // TEST missing family subscription configuration; removing link
        $familySubscriptionType = $this->familySubscriptionTypesRepository->findByMasterSubscriptionType($masterSubscriptionType);
        $this->familySubscriptionTypesRepository->delete($familySubscriptionType);

        $masterUser = $this->createUser('master@example.com');

        // create new payment
        $payment = $this->makePayment(
            $masterUser,
            $masterSubscriptionType,
            'now',
            'now',
            $paymentItemCount
        );
        $subscription = $payment->subscription;

        $this->expectException(MissingFamilySubscriptionTypeException::class);
        $this->familyRequests->createFromSubscription($subscription);

        // assert correct number of requests generated
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionFamilyRequests($subscription)
            ->where(['status' => FamilyRequestsRepository::STATUS_CREATED])->fetchAll();
        $this->assertCount(0, $familyRequests);
    }

    private function createUser($email)
    {
        return $this->userManager->addNewUser($email, false, 'unknown', null, false);
    }

    private function makePayment($user, $subscriptionType, string $paidAtString, string $startSubscriptionAtString = null, int $childrenCountToGenerate = null)
    {
        $paymentItemContainer = new PaymentItemContainer();
        if ($childrenCountToGenerate !== null) {
            // generate subscription type payment item with provided count
            $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
                $subscriptionType->id,
                $subscriptionType->name,
                $subscriptionType->price * $childrenCountToGenerate,
                20,
                $childrenCountToGenerate
            ));
        }

        // set subscription start to payment if it's provided
        $startSubscriptionDateTime = ($startSubscriptionAtString ? new \DateTime($startSubscriptionAtString) : null);

        $payment = $this->paymentsRepository->add(
            $subscriptionType,
            $this->paymentGateway,
            $user,
            $paymentItemContainer,
            null,
            1,
            $startSubscriptionDateTime
        );
        $this->paymentsRepository->update($payment, ['paid_at' => new \DateTime($paidAtString)]);
        $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PAID);
        return $this->paymentsRepository->find($payment->id);
    }
}
