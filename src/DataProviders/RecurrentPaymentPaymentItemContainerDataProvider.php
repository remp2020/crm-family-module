<?php

namespace Crm\FamilyModule\DataProviders;

use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\PaymentsModule\DataProviders\RecurrentPaymentPaymentItemContainerDataProviderInterface;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainerFactory;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeItemsRepository;
use Nette\Database\Table\ActiveRow;

final class RecurrentPaymentPaymentItemContainerDataProvider implements RecurrentPaymentPaymentItemContainerDataProviderInterface
{

    public function __construct(
        private FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        private PaymentItemContainerFactory $paymentItemContainerFactory,
        private SubscriptionTypeItemsRepository $subscriptionTypeItemsRepository,
    ) {
    }

    public function createPaymentItemContainer(
        ActiveRow $recurrentPayment,
        ActiveRow $subscriptionType,
    ): ?PaymentItemContainer {
        if (!$this->familySubscriptionTypesRepository->isMasterSubscriptionType($subscriptionType)) {
            return null;
        }

        // for master family subscription, create payment container just by copying previous payment items
        // (of type SubscriptionTypePaymentItem) and ignore all potential price differences (but do not ignore VAT changes)
        $paymentItemContainer = $this->paymentItemContainerFactory->createFromPayment(
            $recurrentPayment->parent_payment,
            [SubscriptionTypePaymentItem::TYPE],
        );

        foreach ($paymentItemContainer->items() as $item) {
            // always force current VAT
            if ($item instanceof SubscriptionTypePaymentItem) {
                if (!$item->getSubscriptionTypeItemId()) {
                    // In case there is no reference to original subscription type item,
                    // we cannot check VAT, therefore skip the check.
                    continue;
                }

                $subscriptionTypeItem = $this->subscriptionTypeItemsRepository->find($item->getSubscriptionTypeItemId());
                if (!$subscriptionTypeItem) {
                    throw new \RuntimeException("Missing subscription type item [{$item->getSubscriptionTypeItemId()}]");
                }
                $item->forceVat($subscriptionTypeItem->vat);
            }
        }
        return $paymentItemContainer;
    }
}
