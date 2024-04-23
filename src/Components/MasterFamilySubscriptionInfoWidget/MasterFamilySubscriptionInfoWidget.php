<?php

namespace Crm\FamilyModule\Components\MasterFamilySubscriptionInfoWidget;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ApplicationModule\Models\Snippet\SnippetRenderer;
use Crm\ApplicationModule\Models\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManager;
use Crm\ApplicationModule\Repositories\SnippetsRepository;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use Nette\Application\LinkGenerator;
use Nette\Localization\Translator;

class MasterFamilySubscriptionInfoWidget extends BaseLazyWidget
{
    private string $templateName = 'master_family_subscription_info_widget.latte';

    private ?string $activationEmailSnippetIdentifier = null;

    public function __construct(
        LazyWidgetManager $lazyWidgetManager,
        private readonly FamilyRequestsRepository $familyRequestsRepository,
        private readonly FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        private readonly UsersRepository $usersRepository,
        private readonly DonateSubscription $donateSubscription,
        private readonly Translator $translator,
        private readonly SubscriptionsRepository $subscriptionsRepository,
        private readonly SnippetsRepository $snippetsRepository,
        private readonly SnippetRenderer $snippetRenderer,
        private readonly LinkGenerator $linkGenerator,
    ) {
        parent::__construct($lazyWidgetManager);
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
            $message = $this->translator->translate('family.components.master_family_subscription_info.modal.error.not_registered');

            $snippet = $this->snippetsRepository->loadByIdentifier($this->activationEmailSnippetIdentifier);
            if ($snippet) {
                $renderedSnippet = $this->snippetRenderer->render([
                    $this->activationEmailSnippetIdentifier,
                    'link' => $this->linkGenerator->link('Family:Requests:default', ['id' => $this->presenter->getParameter('familyRequestCode')])
                ]);

                // convert line breaks for usage in mailto link (https://www.rfc-editor.org/rfc/rfc2368#page-3)
                $text = str_replace('%0A', '%0D%0A', rawurlencode($renderedSnippet));

                $message = $this->translator->translate(
                    'family.components.master_family_subscription_info.modal.error.not_registered_send_link',
                    [
                        'email' => $this->presenter->getParameter('email'),
                        'subject' => rawurlencode($snippet->title),
                        'text' => $text,
                    ]
                );
            }

            $this->getPresenter()->sendJson([
                'status' => 'error',
                'message' => $message,
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

        $isAdmin = $this->getPresenter() instanceof AdminPresenter;
        $this->donateSubscription->releaseFamilyRequest($familyRequest, $isAdmin);

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

    public function setActivationEmailSnippet(string $snippetIdentifier): void
    {
        $this->activationEmailSnippetIdentifier = $snippetIdentifier;
    }
}
