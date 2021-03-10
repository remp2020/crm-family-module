<?php


namespace Crm\FamilyModule\Components\SlaveFamilySubscriptionInfoWidget;

use Crm\ApplicationModule\Widget\BaseWidget;
use Crm\ApplicationModule\Widget\WidgetManager;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Nette\Database\IRow;

class SlaveFamilySubscriptionInfoWidget extends BaseWidget
{
    private $templateName = 'slave_family_subscription_info_widget.latte';

    private $familyRequestsRepository;

    public function __construct(
        WidgetManager $widgetManager,
        FamilyRequestsRepository $familyRequestsRepository
    ) {
        parent::__construct($widgetManager);

        $this->familyRequestsRepository = $familyRequestsRepository;
    }

    public function identifier()
    {
        return 'slavefamilysubscriptioninfowidget';
    }

    public function render(IRow $user)
    {
        $userSlaveFamilyRequests = $this->familyRequestsRepository->slaveUserFamilyRequests($user)
            ->where('status', FamilyRequestsRepository::STATUS_ACCEPTED);

        if (count($userSlaveFamilyRequests) === 0) {
            return;
        }

        $this->template->familyRequests = $userSlaveFamilyRequests;

        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }
}
