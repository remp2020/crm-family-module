<?php

namespace Crm\FamilyModule\Components\FamilyRequestsDashboardWidget;

use Crm\ApplicationModule\Models\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManager;
use Crm\FamilyModule\Models\FamilyRequests;

class FamilyRequestsDashboardWidget extends BaseLazyWidget
{
    private $templateName = 'family_requests_dashboard_widget.latte';

    private $familyRequests;

    public function __construct(
        LazyWidgetManager $lazyWidgetManager,
        FamilyRequests $familyRequests,
    ) {
        parent::__construct($lazyWidgetManager);

        $this->familyRequests = $familyRequests;
    }

    public function header($id = '')
    {
        return 'Family Requests Dashboard';
    }

    public function identifier()
    {
        return 'familyrequestsdashboardwidget';
    }

    public function render()
    {
        $this->template->familyParentsCurrentlyActiveCount = $this->familyRequests->activeFamilyOwnersCount(true);
        $this->template->familyChildrenGeneratedForActiveParentsCount = $this->familyRequests->activeFamilyRequestsCount(true);
        $this->template->paidSubscribersWithFamilyRequestsCount = $this->familyRequests->activePaidSubscribersWithFamilyRequestsCount(true);

        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }
}
