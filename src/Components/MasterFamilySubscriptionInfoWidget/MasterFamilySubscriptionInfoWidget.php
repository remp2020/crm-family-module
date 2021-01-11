<?php


namespace Crm\FamilyModule\Components\MasterFamilySubscriptionInfoWidget;

use Crm\ApplicationModule\Widget\BaseWidget;
use Crm\ApplicationModule\Widget\WidgetManager;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Nette\Database\IRow;

class MasterFamilySubscriptionInfoWidget extends BaseWidget
{
    private $templateName = 'master_family_subscription_info_widget.latte';

    private $familyRequestsRepository;

    private $familySubscriptionTypesRepository;

    private $familySubscriptionsRepository;

    private $usersRepository;

    private $donateSubscription;

    public function __construct(
        WidgetManager $widgetManager,
        FamilyRequestsRepository $familyRequestsRepository,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        FamilySubscriptionsRepository $familySubscriptionsRepository,
        UsersRepository $usersRepository,
        DonateSubscription $donateSubscription
    ) {
        parent::__construct($widgetManager);

        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
        $this->familySubscriptionsRepository = $familySubscriptionsRepository;
        $this->usersRepository = $usersRepository;
        $this->donateSubscription = $donateSubscription;
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

        $this->template->subscriptionsData = $this->getSubscriptionsData($userMasterSubscriptions);

        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }

    private function getSubscriptionsData($subscriptions)
    {
        $subscriptionsData = [];
        foreach ($subscriptions as $subscription) {
            $subscriptionsData[$subscription->id] = [
                'subscription' => $subscription,
                'usedFamilyRequests' => $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($subscription),
                'activeFamilyRequests' => $this->familyRequestsRepository->masterSubscriptionActiveFamilyRequests($subscription),
                'canceledFamilyRequests' => $this->familyRequestsRepository->masterSubscriptionCanceledFamilyRequests($subscription),
                'familyType' => $this->familySubscriptionTypesRepository->findByMasterSubscriptionType($subscription->subscription_type)
            ];
            foreach ($subscriptionsData[$subscription->id]['activeFamilyRequests'] as $familyRequest) {
                $familySubscription = $familyRequest->related('family_subscriptions')->fetch();
                $subscriptionsData[$subscription->id]['familySubscriptionsByRequest'][$familyRequest->id] = $familySubscription;
            }
        }
        return $subscriptionsData;
    }

    public function handleActivateSubscription()
    {
        $user = $this->usersRepository->getByEmail($this->presenter->getParameter('email'));
        if (!$user) {
            return;
        }

        $familyRequest = $this->familyRequestsRepository->findByCode($this->presenter->getParameter('familyRequestCode'));
        if (!$familyRequest) {
            return;
        }

        $this->donateSubscription->connectFamilyUser($user, $familyRequest);
        $this->redirect('this');
    }

    public function handleDeactivateSubscription($id)
    {
        $familySubscription = $this->familySubscriptionsRepository->find($id);
        if (!$familySubscription) {
            return;
        }

        $this->donateSubscription->stopFamilySubscription($familySubscription);
        $this->familyRequestsRepository->add(
            $familySubscription->master_subscription,
            $familySubscription->slave_subscription->subscription_type
        );

        $this->redirect('this');
    }
}
