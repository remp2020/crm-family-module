<?php

namespace Crm\FamilyModule\Tests;

use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\FamilyModule\FamilyModule;
use Crm\PaymentsModule\Models\GatewayFactory;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Models\PaymentProcessor;
use Crm\PaymentsModule\Models\RecurrentPaymentsResolver;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\PaymentsModule\Seeders\TestPaymentGatewaysSeeder;
use Crm\PaymentsModule\Tests\Gateways\TestRecurrentGateway;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\UsersModule\Models\Auth\UserManager;
use Nette\Database\Table\ActiveRow;

final class RecurrentPaymentPaymentItemContainerDataProviderTest extends BaseTestCase
{

    /** @var PaymentsRepository */
    private $paymentsRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);

        $gatewayFactory = $this->inject(GatewayFactory::class);
        $gatewayFactory->registerGateway(TestRecurrentGateway::GATEWAY_CODE, TestRecurrentGateway::class);

        $familyModule = $this->container->createInstance(FamilyModule::class);
        $familyModule->registerDataProviders($this->inject(DataProviderManager::class));
    }

    protected function requiredSeeders(): array
    {
        return [
            ...parent::requiredSeeders(),
            TestPaymentGatewaysSeeder::class,
        ];
    }

    public function testRecurrentPaymentRenewalOfCustomizableMasterSubscription()
    {
        [$masterSubscriptionType, $printSlaveSubscriptionType, $webSlaveSubscriptionType] = $this->seedFamilyCustomizableSubscriptionType();

        $user = $this->inject(UserManager::class)->addNewUser('user@example.com', false, 'unknown', null, false);
        $paymentGateway = $this->getRepository(PaymentGatewaysRepository::class)
            ->findByCode(TestRecurrentGateway::GATEWAY_CODE);

        // Payment item of customizable master subscription can have different payment items than associated with
        // subscription type
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

        $payment = $this->makePayment($user, $masterSubscriptionType, $paymentGateway, $paymentItemContainer);

        /** @var RecurrentPaymentsRepository $recurrentPaymentsRepository */
        $recurrentPaymentsRepository = $this->getRepository(RecurrentPaymentsRepository::class);
        $recurrentPayment = $recurrentPaymentsRepository->recurrent($payment);

        /** @var RecurrentPaymentsResolver $recurrentPaymentsResolver */
        $recurrentPaymentsResolver = $this->inject(RecurrentPaymentsResolver::class);
        $paymentData = $recurrentPaymentsResolver->resolvePaymentData($recurrentPayment);

        // Assert payment items are correctly copied from parent payment,
        // differences in price, amount and VAT should be ignored
        $items = $paymentData->paymentItemContainer->items();
        $this->assertEquals(10, $items[0]->unitPrice());
        $this->assertEquals(20, $items[0]->vat());
        $this->assertEquals(2, $items[0]->count());

        $this->assertEquals(5, $items[1]->unitPrice());
        $this->assertEquals(20, $items[1]->vat());
        $this->assertEquals(3, $items[1]->count());
    }

    private function makePayment(
        ActiveRow $user,
        ActiveRow $subscriptionType,
        ActiveRow $paymentGateway,
        PaymentItemContainer $paymentItemContainer
    ) {
        $payment = $this->paymentsRepository->add(
            subscriptionType: $subscriptionType,
            paymentGateway: $paymentGateway,
            user: $user,
            paymentItemContainer: $paymentItemContainer,
        );

        // Make manual payment
        $this->inject(PaymentProcessor::class)->complete($payment, fn() => null);
        return $this->paymentsRepository->find($payment->id);
    }
}
