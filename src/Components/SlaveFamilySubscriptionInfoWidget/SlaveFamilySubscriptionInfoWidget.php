<?php


namespace Crm\FamilyModule\Components\SlaveFamilySubscriptionInfoWidget;

use Crm\ApplicationModule\Widget\BaseWidget;
use Crm\ApplicationModule\Widget\WidgetManager;
use Crm\FamilyModule\Repositories\FamilySubscriptionsRepository;
use Nette\Database\IRow;

class SlaveFamilySubscriptionInfoWidget extends BaseWidget
{
    private $templateName = 'slave_family_subscription_info_widget.latte';

    private $familySubscriptionsRepository;

    public function __construct(
        WidgetManager $widgetManager,
        FamilySubscriptionsRepository $familySubscriptionsRepository
    ) {
        parent::__construct($widgetManager);

        $this->familySubscriptionsRepository = $familySubscriptionsRepository;
    }

    public function identifier()
    {
        return 'slavefamilysubscriptioninfowidget';
    }

    public function render(IRow $user)
    {
        $userSlaveFamilySubscriptions = $this->familySubscriptionsRepository->findActiveUserSlaveFamilySubscriptions($user);

        if (count($userSlaveFamilySubscriptions) == 0) {
            return;
        }

        $this->template->familySubscriptions = $userSlaveFamilySubscriptions;

        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }
}
