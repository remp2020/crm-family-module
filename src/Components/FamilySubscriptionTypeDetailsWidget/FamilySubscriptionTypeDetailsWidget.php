<?php

namespace Crm\FamilyModule\Components\FamilySubscriptionTypeDetailsWidget;

use Crm\ApplicationModule\Models\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManager;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Nette\Database\Table\ActiveRow;

class FamilySubscriptionTypeDetailsWidget extends BaseLazyWidget
{
    private $templateName = 'family_subscription_type_details_widget.latte';

    private $familySubscriptionTypesRepository;

    public function __construct(
        LazyWidgetManager $lazyWidgetManager,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository
    ) {
        parent::__construct($lazyWidgetManager);

        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
    }

    public function header($id = '')
    {
        return 'Family subscription type details widget';
    }

    public function identifier()
    {
        return 'familysubscriptiontypedetailswidget';
    }

    public function render(ActiveRow $subscriptionType): void
    {
        // find parent
        $parentFamilySubscriptionType = $this->familySubscriptionTypesRepository->findByMasterSubscriptionType($subscriptionType);
        if ($parentFamilySubscriptionType) {
            $this->template->parentFamilySubscriptionType = $parentFamilySubscriptionType;
        }

        // find child
        $childFamilySubscriptionTypes = $this->familySubscriptionTypesRepository->findBySlaveSubscriptionType($subscriptionType);
        if (!empty($childFamilySubscriptionTypes)) {
            $this->template->childFamilySubscriptionTypes = $childFamilySubscriptionTypes;
        }

        if (!$parentFamilySubscriptionType && empty($childFamilySubscriptionTypes)) {
            // not family/company subscription type; do not render widget
            return;
        }

        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }
}
