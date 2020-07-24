<?php


namespace Crm\FamilyModule\Components\MasterFamilySubscriptionInfoWidget;

use Crm\ApplicationModule\Widget\BaseWidget;
use Crm\ApplicationModule\Widget\WidgetManager;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Nette\Database\IRow;

class MasterFamilySubscriptionInfoWidget extends BaseWidget
{
    private $templateName = 'master_family_subscription_info_widget.latte';

    private $familyRequestsRepository;

    private $familySubscriptionTypesRepository;

    private $subscriptionsRepository;

    private $usersRepository;


    public function __construct(
        WidgetManager $widgetManager,
        FamilyRequestsRepository $familyRequestsRepository,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        SubscriptionsRepository $subscriptionsRepository,
        UsersRepository $usersRepository
    ) {
        parent::__construct($widgetManager);

        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->usersRepository = $usersRepository;
    }

    public function identifier()
    {
        return 'masterfamilysubscriptioninfowidget';
    }

    public function render(IRow $user)
    {
        $userMasterSubscriptions = $this->familyRequestsRepository->userMasterSubscriptions($user);

        if (count($userMasterSubscriptions) == 0) {
            return;
        }

        $this->template->subscriptions = $userMasterSubscriptions;
        $this->template->subscriptionsData = $this->getSubscriptionsAdditionalData($userMasterSubscriptions);

        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }

    private function getSubscriptionsAdditionalData($subscriptions)
    {
        $subscriptionsData = [];
        foreach ($subscriptions as $subscription) {
            $subscriptionsData[$subscription->id] = [
                'usedFamilyRequests' => $this->familyRequestsRepository->masterSubscriptionUsedFamilyRequests($subscription)->count('*'),
                'totalFamilyRequests' => $this->familyRequestsRepository->masterSubscriptionFamilyRequest($subscription)->count('*'),
                'familyType' => $this->familySubscriptionTypesRepository->findByMasterSubscriptionType($subscription->subscription_type)
            ];
        }
        return $subscriptionsData;
    }
}
