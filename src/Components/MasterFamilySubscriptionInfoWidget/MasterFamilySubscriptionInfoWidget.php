<?php

namespace Crm\FamilyModule\Components\MasterFamilySubscriptionInfoWidget;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ApplicationModule\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Widget\LazyWidgetManager;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use Nette\Localization\Translator;

class MasterFamilySubscriptionInfoWidget extends BaseLazyWidget
{
    private $templateName = 'master_family_subscription_info_widget.latte';

    private $familyRequestsRepository;

    private $familySubscriptionTypesRepository;

    private $usersRepository;

    private $donateSubscription;

    private $translator;

    private $subscriptionsRepository;

    public function __construct(
        LazyWidgetManager $lazyWidgetManager,
        FamilyRequestsRepository $familyRequestsRepository,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        UsersRepository $usersRepository,
        DonateSubscription $donateSubscription,
        Translator $translator,
        SubscriptionsRepository $subscriptionsRepository
    ) {
        parent::__construct($lazyWidgetManager);

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

        $hasFamilySubscription = true;
        if (count($userMasterSubscriptions) === 0) {
            $hasFamilySubscription = false;
        }

        $isAdmin = false;
        if ($this->getPresenter() instanceof AdminPresenter) {
            $isAdmin = true;
        }

        if (!$hasFamilySubscription && !$isAdmin) {
            return;
        }
        $this->template->isAdmin = $isAdmin;
        $this->template->userId = $userId;
        $this->template->hasFamilySubscription = $hasFamilySubscription;
        $this->template->subscriptionsData = $this->getSubscriptionsData($userMasterSubscriptions);
        $this->template->showAddButton = $isAdmin && $this->familySubscriptionTypesRepository->getCustomizableSubscriptionTypes();

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
            $this->getPresenter()->sendJson([
                'status' => 'error',
                'message' => $this->translator->translate('family.components.master_family_subscription_info.modal.error.not_registered'),
            ]);
        }

        $familyRequest = $this->familyRequestsRepository->findByCode($this->presenter->getParameter('familyRequestCode'));
        if (!$familyRequest) {
            return;
        }

        $donateResponse = $this->donateSubscription->connectFamilyUser($user, $familyRequest);
        if (is_string($donateResponse) && $donateResponse === DonateSubscription::ERROR_REQUEST_WRONG_STATUS) {
            $this->getPresenter()->sendJson([
                'status' => 'error',
                'message' => $this->translator->translate('family.components.master_family_subscription_info.modal.error.wrong_status'),
            ]);
        }

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
