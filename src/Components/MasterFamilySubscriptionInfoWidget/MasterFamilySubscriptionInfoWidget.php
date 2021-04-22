<?php

namespace Crm\FamilyModule\Components\MasterFamilySubscriptionInfoWidget;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ApplicationModule\Widget\BaseWidget;
use Crm\ApplicationModule\Widget\WidgetManager;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Kdyby\Translation\ITranslator;

class MasterFamilySubscriptionInfoWidget extends BaseWidget
{
    private $templateName = 'master_family_subscription_info_widget.latte';

    private $familyRequestsRepository;

    private $familySubscriptionTypesRepository;

    private $usersRepository;

    private $donateSubscription;

    private $translator;

    private $subscriptionsRepository;

    public function __construct(
        WidgetManager $widgetManager,
        FamilyRequestsRepository $familyRequestsRepository,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        UsersRepository $usersRepository,
        DonateSubscription $donateSubscription,
        ITranslator $translator,
        SubscriptionsRepository $subscriptionsRepository
    ) {
        parent::__construct($widgetManager);

        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
        $this->usersRepository = $usersRepository;
        $this->donateSubscription = $donateSubscription;
        $this->translator = $translator;
        $this->subscriptionsRepository = $subscriptionsRepository;
    }

    public function identifier()
    {
        return 'masterfamilysubscriptioninfowidget';
    }

    public function render(int $userId)
    {
        $user = $this->usersRepository->find($userId);
        $userMasterSubscriptions = $this->subscriptionsRepository->userSubscriptions($user->id)
            ->where('subscription_type_id IN ?', $this->familySubscriptionTypesRepository->masterSubscriptionTypes());

        if (count($userMasterSubscriptions) === 0) {
            return;
        }

        $isAdmin = false;
        if ($this->getPresenter() instanceof AdminPresenter) {
            $isAdmin = true;
        }
        $this->template->isAdmin = $isAdmin;

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
        }
        return $subscriptionsData;
    }

    public function handleActivateSubscription()
    {
        $user = $this->usersRepository->getByEmail($this->presenter->getParameter('email'));
        if (!$user) {
            $presenter = $this->getPresenter();
            $presenter->sendJson([
                'status' => 'error',
                'message' => $this->translator->translate('family.components.master_family_subscription_info.modal.error.not_registered'),
            ]);
        }

        $familyRequest = $this->familyRequestsRepository->findByCode($this->presenter->getParameter('familyRequestCode'));
        if (!$familyRequest) {
            return;
        }

        $this->donateSubscription->connectFamilyUser($user, $familyRequest);
        $this->redirect('this');
    }

    public function handleDeactivateSubscription($requestId)
    {
        $familyRequest = $this->familyRequestsRepository->find($requestId);
        if (!$familyRequest) {
            return;
        }
        $this->donateSubscription->releaseFamilyRequest($familyRequest);

        $this->redirect('this');
    }

    public function handleSaveNote()
    {
        $familyRequest = $this->familyRequestsRepository->findByCode($this->presenter->getParameter('familyRequestCode'));
        if (!$familyRequest) {
            $presenter = $this->getPresenter();
            $presenter->sendJson([
                'status' => 'error'
            ]);
            return;
        }

        $this->familyRequestsRepository->update($familyRequest, [
            'updated_at' => new \DateTime(),
            'note' => substr($this->presenter->getParameter('note'), 0, 255)
        ]);

        $this->redirect('this');
    }
}
