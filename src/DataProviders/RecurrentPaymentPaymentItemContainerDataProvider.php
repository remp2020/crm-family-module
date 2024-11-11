<?php

namespace Crm\FamilyModule\DataProviders;

use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\PaymentsModule\DataProviders\RecurrentPaymentPaymentItemContainerDataProviderInterface;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainerFactory;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Nette\Database\Table\ActiveRow;

final class RecurrentPaymentPaymentItemContainerDataProvider implements RecurrentPaymentPaymentItemContainerDataProviderInterface
{

    public function __construct(
        private FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        private PaymentItemContainerFactory $paymentItemContainerFactory,
    ) {
    }

    public function createPaymentItemContainer(
        ActiveRow $recurrentPayment,
        ActiveRow $subscriptionType,
    ): ?PaymentItemContainer {
        // for master family subscription, create payment container just by copying previous payment items
        // (of type SubscriptionTypePaymentItem) and ignore all potential price differences
        if ($this->familySubscriptionTypesRepository->isMasterSubscriptionType($subscriptionType)) {
            return $this->paymentItemContainerFactory->createFromPayment(
                $recurrentPayment->parent_payment,
                [SubscriptionTypePaymentItem::TYPE]
            );
        }
        return null;
    }
}
