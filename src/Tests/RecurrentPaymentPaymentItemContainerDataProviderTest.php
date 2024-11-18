<?php

namespace Crm\FamilyModule\Tests;

use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Seeders\CountriesSeeder;
use Crm\FamilyModule\DataProviders\RecurrentPaymentPaymentItemContainerDataProvider;
use Crm\PaymentsModule\DataProviders\RecurrentPaymentPaymentItemContainerDataProviderInterface;
use Crm\PaymentsModule\Models\GatewayFactory;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Models\PaymentProcessor;
use Crm\PaymentsModule\Models\RecurrentPaymentsResolver;
use Crm\PaymentsModule\Models\RecurrentPaymentsResolver\PaymentData;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\PaymentsModule\Repositories\VatRatesRepository;
use Crm\PaymentsModule\Seeders\TestPaymentGatewaysSeeder;
use Crm\PaymentsModule\Tests\Gateways\TestRecurrentGateway;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeItemsRepository;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Repositories\CountriesRepository;
use Nette\Database\Table\ActiveRow;

final class RecurrentPaymentPaymentItemContainerDataProviderTest extends BaseTestCase
{
    private PaymentsRepository $paymentsRepository;
    private SubscriptionTypeItemsRepository $subscriptionTypeItemsRepository;
    private CountriesRepository $countriesRepository;
    private VatRatesRepository $vatRatesRepository;
    private RecurrentPaymentsRepository $recurrentPaymentsRepository;
    private RecurrentPaymentsResolver $recurrentPaymentsResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subscriptionTypeItemsRepository = $this->getRepository(SubscriptionTypeItemsRepository::class);
        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->countriesRepository = $this->getRepository(CountriesRepository::class);
        $this->recurrentPaymentsRepository = $this->getRepository(RecurrentPaymentsRepository::class);
        $this->vatRatesRepository = $this->getRepository(VatRatesRepository::class);

        $this->recurrentPaymentsResolver = $this->inject(RecurrentPaymentsResolver::class);

        $gatewayFactory = $this->inject(GatewayFactory::class);
        if (!in_array(TestRecurrentGateway::GATEWAY_CODE, $gatewayFactory->getRegisteredCodes(), true)) {
            $gatewayFactory->registerGateway(TestRecurrentGateway::GATEWAY_CODE, TestRecurrentGateway::class);
        }

        $dataProviderManager = $this->inject(DataProviderManager::class);
        $dataProviderManager->registerDataProvider(
            RecurrentPaymentPaymentItemContainerDataProviderInterface::PATH,
            $this->inject(RecurrentPaymentPaymentItemContainerDataProvider::class)
        );

        // Setting countries and VATs
        // in all tests, we use France as foreign country
        $this->countriesRepository->setDefaultCountry('SK');
        $this->vatRatesRepository->upsert($this->countriesRepository->defaultCountry(), 20, 10, 10);
    }

    protected function requiredRepositories(): array
    {
        return [
            ...parent::requiredRepositories(),
            SubscriptionTypeItemsRepository::class,
            CountriesRepository::class,
            VatRatesRepository::class,
        ];
    }


    protected function requiredSeeders(): array
    {
        return [
            ...parent::requiredSeeders(),
            CountriesSeeder::class,
            TestPaymentGatewaysSeeder::class,
        ];
    }

    public function testRecurrentPaymentRenewalOfCustomizableMasterSubscription()
    {
        [$masterSubscriptionType, $printSlaveSubscriptionType, $webSlaveSubscriptionType] = $this->seedFamilyCustomizableSubscriptionType();
        $masterWebSubscriptionTypeItem = $this->subscriptionTypeItemsRepository->subscriptionTypeItems($masterSubscriptionType)
            ->where('name', 'Web')
            ->fetch();
        $masterPrintSubscriptionTypeItem = $this->subscriptionTypeItemsRepository->subscriptionTypeItems($masterSubscriptionType)
            ->where('name', 'Print')
            ->fetch();

        $user = $this->inject(UserManager::class)->addNewUser('user@example.com', false, 'unknown', null, false);
        $paymentGateway = $this->getRepository(PaymentGatewaysRepository::class)
            ->findByCode(TestRecurrentGateway::GATEWAY_CODE);

        // Setting custom price and amount (VAT is 20% in seeded subscription types)
        SubscriptionTypePaymentItem::fromSubscriptionType($masterSubscriptionType);
        $paymentItemContainer = new PaymentItemContainer();
        $paymentItemContainer->addItem(
            SubscriptionTypePaymentItem::fromSubscriptionTypeItem($masterWebSubscriptionTypeItem)
            ->forceCount(2)
            ->forcePrice(10)
        );
        $paymentItemContainer->addItem(
            SubscriptionTypePaymentItem::fromSubscriptionTypeItem($masterPrintSubscriptionTypeItem)
                ->forceCount(3)
                ->forcePrice(5)
        );

        // Assert payment items are correctly copied from parent payment,
        // differences in prices should be ignored
        // VAT changes should NOT be ignored

        $payment1 = $this->makePayment($user, $masterSubscriptionType, $paymentGateway, $paymentItemContainer);
        $payment2 = $this->makeRecurrentPayment($payment1, $paymentGateway);
        $this->assertPaymentItemsEqualTo($payment2, [
            'web' => ['name' => 'Web', 'amount' => 10, 'vat' => 20, 'count' => 2],
            'print' => ['name' => 'Print', 'amount' => 5, 'vat' => 20, 'count' => 3],
        ]);

        // Change VAT for Print
        $this->subscriptionTypeItemsRepository->update($masterPrintSubscriptionTypeItem, [
            'vat' => 12,
        ], true);

        $payment3 = $this->makeRecurrentPayment($payment2, $paymentGateway);
        $this->assertPaymentItemsEqualTo($payment3, [
            'web' => ['name' => 'Web', 'amount' => 10, 'vat' => 20, 'count' => 2],
            'print' => ['name' => 'Print', 'amount' => 5, 'vat' => 12, 'count' => 3],
        ]);
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

    private function makeRecurrentPayment(ActiveRow $parentPayment, ActiveRow $paymentGateway): ActiveRow
    {
        $recurrent = $this->recurrentPaymentsRepository->recurrent($parentPayment);

        /** @var PaymentData $paymentData */
        $paymentData = $this->recurrentPaymentsResolver->resolvePaymentData($recurrent);

        $payment = $this->paymentsRepository->add(
            subscriptionType: $paymentData->subscriptionType,
            paymentGateway: $paymentGateway,
            user: $recurrent->user,
            paymentItemContainer: $paymentData->paymentItemContainer,
            recurrentCharge: true,
            paymentCountry: $paymentData->paymentCountry,
        );

        $this->recurrentPaymentsRepository->update($recurrent, [
            'payment_id' => $payment->id,
        ]);

        $this->inject(PaymentProcessor::class)->complete($payment, fn() => null);
        $payment = $this->paymentsRepository->find($payment->id);
        $this->recurrentPaymentsRepository->setCharged($recurrent, $payment, 'OK', 'OK');
        return $payment;
    }

    private function assertPaymentItemsEqualTo(ActiveRow $payment, array $itemsToCompare): void
    {
        $paymentItemsData = [];
        foreach ($payment->related('payment_items') as $pi) {
            $paymentItemsData[$pi->name] = ['name' => $pi->name, 'amount' => $pi->amount, 'vat' => $pi->vat, 'count' => $pi->count];
        }
        sort($itemsToCompare);
        sort($paymentItemsData);
        foreach (array_keys($itemsToCompare) as $k) {
            $this->assertEqualsCanonicalizing($itemsToCompare[$k], $paymentItemsData[$k]);
        }
        // check sum of payment
        $sumItemsToCompare = 0;
        foreach ($itemsToCompare as $item) {
            $sumItemsToCompare += $item['amount'] * $item['count'];
        }
        $this->assertEquals($payment->amount, $sumItemsToCompare);
    }
}
