<?php

namespace Crm\FamilyModule\Components\FamilyRequestsDashboardWidget;

use Crm\ApplicationModule\Widget\BaseWidget;
use Crm\ApplicationModule\Widget\WidgetManager;
use Crm\FamilyModule\Models\FamilyRequests;

class FamilyRequestsDashboardWidget extends BaseWidget
{
    private $templateName = 'family_requests_dashboard_widget.latte';

    private $familyRequests;

    public function __construct(
        WidgetManager $widgetManager,
        FamilyRequests $familyRequests
    ) {
        parent::__construct($widgetManager);

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
