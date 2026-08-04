<?php

namespace Crm\FamilyModule\Components\MasterFamilySubscriptionInfoWidget;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ApplicationModule\Models\NowTrait;
use Crm\ApplicationModule\Models\Snippet\SnippetRenderer;
use Crm\ApplicationModule\Models\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManager;
use Crm\ApplicationModule\Repositories\SnippetsRepository;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UsersModule\Models\User\UnclaimedUser;
use Crm\UsersModule\Repositories\UsersRepository;
use DateTime;
use Nette\Application\Attributes\Persistent;
use Nette\Application\LinkGenerator;
use Nette\Localization\Translator;

class MasterFamilySubscriptionInfoWidget extends BaseLazyWidget
{
    use NowTrait;

    private string $templateName = 'master_family_subscription_info_widget.latte';

    private ?string $activationEmailSnippetIdentifier = null;

    private bool $showInactiveSubscriptions = false;

    #[Persistent]
    public int $userId;

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
        private readonly UnclaimedUser $unclaimedUser,
    ) {
        parent::__construct($lazyWidgetManager);
    }

    public function identifier(): string
    {
        return 'masterfamilysubscriptioninfowidget';
    }

    public function render(?int $userId = null): void
    {
        if ($userId !== null) {
            $this->userId = $userId;
        }

        $isAdmin = $this->getPresenter() instanceof AdminPresenter;
        if (!$isAdmin) {
            $this->userId = $this->getPresenter()->getUser()->getId();
        }

        $userMasterSubscriptions = $this->subscriptionsRepository->userSubscriptions($this->userId)
            ->where(
                'subscriptions.subscription_type_id IN (?) OR :family_requests(master_subscription_id).id IS NOT NULL',
                $this->familySubscriptionTypesRepository->masterSubscriptionTypes(),
            );

        $now = $this->getNow();
        $subscriptionsData = $this->getSubscriptionsData(
            (clone $userMasterSubscriptions)->where('subscriptions.end_time >= ?', $now),
        );

        $inactiveSubscriptions = (clone $userMasterSubscriptions)
            ->where('subscriptions.end_time < ?', $now)
            ->where(
                ':family_requests(master_subscription_id).status',
                FamilyRequestsRepository::STATUS_ACCEPTED,
            );

        $canLoadInactiveSubscriptions = false;
        if ($this->showInactiveSubscriptions) {
            $subscriptionsData = array_merge($subscriptionsData, $this->getSubscriptionsData($inactiveSubscriptions));
        } else {
            $canLoadInactiveSubscriptions = $inactiveSubscriptions->count('*') > 0;
        }

        $showTable = count($subscriptionsData) > 0 || $canLoadInactiveSubscriptions;
        if (!$showTable && !$isAdmin) {
            return;
        }

        $this->template->isAdmin = $isAdmin;
        $this->template->userId = $this->userId;
        $this->template->showTable = $showTable;
        $this->template->subscriptionsData = $subscriptionsData;
        $this->template->canLoadInactiveSubscriptions = $canLoadInactiveSubscriptions;
        $this->template->showAddButton = $isAdmin && $this->familySubscriptionTypesRepository->getCustomizableSubscriptionTypes();

        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }

    public function handleLoadInactiveSubscriptions(): void
    {
        $this->showInactiveSubscriptions = true;

        if ($this->getPresenter()->isAjax()) {
            $this->redrawControl('subscriptions');
            $this->redrawControl('subscriptionModals');
            $this->redrawControl('loadInactiveButton');
        }
    }

    private function getSubscriptionsData($subscriptions): array
    {
        $subscriptionsData = [];
        foreach ($subscriptions as $subscription) {
            $usedFamilyRequests = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($subscription)->count('*');
            $subscriptionsData[$subscription->id] = [
                'subscription' => $subscription,
                'usedFamilyRequests' => $usedFamilyRequests,
                'activeFamilyRequests' => $this->familyRequestsRepository->masterSubscriptionActiveFamilyRequests($subscription),
                'canceledFamilyRequests' => $this->familyRequestsRepository->masterSubscriptionCanceledFamilyRequests($subscription),
                'familyType' => $this->familySubscriptionTypesRepository->findByMasterSubscriptionType($subscription->subscription_type),
            ];
        }
        return $subscriptionsData;
    }

    public function handleActivateSubscription(): void
    {
        $user = $this->usersRepository->getByEmail($this->presenter->getParameter('email'));
        if (!$user || $this->unclaimedUser->isUnclaimedUser($user)) {
            $message = $this->translator->translate('family.components.master_family_subscription_info.modal.error.not_registered');

            $snippet = $this->snippetsRepository->loadByIdentifier($this->activationEmailSnippetIdentifier);
            if ($snippet) {
                $renderedSnippet = $this->snippetRenderer->render([
                    $this->activationEmailSnippetIdentifier,
                    'link' => $this->linkGenerator->link('Family:Requests:default', ['id' => $this->presenter->getParameter('familyRequestCode')]),
                ]);

                // convert line breaks for usage in mailto link (https://www.rfc-editor.org/rfc/rfc2368#page-3)
                $text = str_replace('%0A', '%0D%0A', rawurlencode($renderedSnippet));

                $message = $this->translator->translate(
                    'family.components.master_family_subscription_info.modal.error.not_registered_send_link',
                    [
                        'email' => $this->presenter->getParameter('email'),
                        'subject' => rawurlencode($snippet->title),
                        'text' => $text,
                    ],
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
        if ($donateResponse === DonateSubscription::ERROR_REQUEST_WRONG_STATUS) {
            $this->getPresenter()->sendJson([
                'status' => 'error',
                'message' => $this->translator->translate('family.components.master_family_subscription_info.modal.error.wrong_status'),
            ]);
        }

        $this->redirect('this');
    }

    public function handleDeactivateSubscription($requestId): void
    {
        $familyRequest = $this->familyRequestsRepository->find($requestId);
        if (!$familyRequest) {
            return;
        }

        $isAdmin = $this->getPresenter() instanceof AdminPresenter;
        $this->donateSubscription->releaseFamilyRequest($familyRequest, $isAdmin);

        $this->redirect('this');
    }

    public function handleSaveNote(): void
    {
        $familyRequest = $this->familyRequestsRepository->findByCode($this->presenter->getParameter('familyRequestCode'));
        if (!$familyRequest) {
            $this->getPresenter()->sendJson([
                'status' => 'error',
            ]);
        }

        $this->familyRequestsRepository->update($familyRequest, [
            'updated_at' => new DateTime(),
            'note' => substr($this->presenter->getParameter('note'), 0, 255),
        ]);

        $this->donateSubscription->syncNoteToNextSubscription(
            $this->familyRequestsRepository->find($familyRequest->id),
        );

        $this->redirect('this');
    }

    public function setActivationEmailSnippet(string $snippetIdentifier): void
    {
        $this->activationEmailSnippetIdentifier = $snippetIdentifier;
    }
}
