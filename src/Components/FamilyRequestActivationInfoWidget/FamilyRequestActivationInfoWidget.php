<?php

namespace Crm\FamilyModule\Components\FamilyRequestActivationInfoWidget;

use Crm\ApplicationModule\Models\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManager;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Nette\Database\Table\ActiveRow;

class FamilyRequestActivationInfoWidget extends BaseLazyWidget
{
    private string $templateName = 'family_request_activation_info_widget.latte';

    public function __construct(
        LazyWidgetManager $lazyWidgetManager,
        private readonly PaymentMetaRepository $paymentMetaRepository,
        private readonly FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
    ) {
        parent::__construct($lazyWidgetManager);
    }

    public function render(ActiveRow $payment): void
    {
        if (!$payment->subscription_type_id) {
            return;
        }

        $isFamilySubscriptionType = $this->familySubscriptionTypesRepository
            ->isMasterSubscriptionType(subscriptionType: $payment->subscription_type);

        if (!$isFamilySubscriptionType) {
            return;
        }

        $keepRequestsUnactivated = $this->paymentMetaRepository->findByPaymentAndKey(
            payment: $payment,
            key: FamilyRequests::KEEP_REQUESTS_UNACTIVATED_PAYMENT_META,
        );

        $this->template->willActivate = !$keepRequestsUnactivated;
        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }
}
