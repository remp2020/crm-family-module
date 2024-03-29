<?php

namespace Crm\FamilyModule\Tests;

use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Models\InvalidConfigurationException;
use Crm\FamilyModule\Models\MissingFamilySubscriptionTypeException;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\PaymentStatusChangeHandler;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UsersModule\Models\Auth\UserManager;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

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

        $emitter = $this->inject(LazyEventEmitter::class);
        // To create subscriptions from payments, register listener
        $emitter->addListener(PaymentChangeStatusEvent::class, $this->inject(PaymentStatusChangeHandler::class));
    }

    protected function tearDown(): void
    {
        $this->inject(LazyEventEmitter::class)->removeAllListeners(PaymentChangeStatusEvent::class);

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

    public function testWithCustomizableMasterSubscription()
    {
        [$masterSubscriptionType, $printSlaveSubscriptionType, $webSlaveSubscriptionType] = $this->seedFamilyCustomizableSubscriptionType();

        $masterUser = $this->createUser('master@example.com');

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

        $payment = $this->paymentsRepository->add(
            $masterSubscriptionType,
            $this->paymentGateway,
            $masterUser,
            $paymentItemContainer,
            null,
            1,
            new DateTime()
        );

        $this->paymentsRepository->update($payment, ['paid_at' => new DateTime()]);
        $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PAID);

        $payment = $this->paymentsRepository->find($payment->id);
        $subscription = $payment->subscription;

        $requests = $this->familyRequests->createFromSubscription($payment->subscription);
        $this->assertCount(5, $requests);

        $familyRequests = $this->familyRequestsRepository->masterSubscriptionFamilyRequests($subscription)
            ->where(['status' => FamilyRequestsRepository::STATUS_CREATED]);

        $this->assertSame(3, (clone $familyRequests)->where(['subscription_type_id' => $printSlaveSubscriptionType->id])->count('*'));
        $this->assertSame(2, (clone $familyRequests)->where(['subscription_type_id' => $webSlaveSubscriptionType->id])->count('*'));
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
