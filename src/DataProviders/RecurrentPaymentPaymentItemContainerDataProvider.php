<?php

namespace Crm\FamilyModule\DataProviders;

use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\PaymentsModule\DataProviders\RecurrentPaymentPaymentItemContainerDataProviderInterface;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainerFactory;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;

final class RecurrentPaymentPaymentItemContainerDataProvider implements RecurrentPaymentPaymentItemContainerDataProviderInterface
{

    public function __construct(
        private FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        private PaymentItemContainerFactory $paymentItemContainerFactory,
    ) {
    }

    public function provide(array $params): ?PaymentItemContainer
    {
        $recurrentPayment = $params['recurrent_payment'];
        $subscriptionType = $params['subscription_type'];

        // for master family subscription, create payment container just by copying previous payment items (of type SubscriptionTypePaymentItem)
        // and ignore all potential price differences
        if ($this->familySubscriptionTypesRepository->isMasterSubscriptionType($subscriptionType)) {
            return $this->paymentItemContainerFactory->createFromPayment(
                $recurrentPayment->parent_payment,
                [SubscriptionTypePaymentItem::TYPE]
            );
        }

        return null;
    }
}
