<?php

namespace Crm\FamilyModule\Presenters;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\FamilyModule\Forms\RequestFormFactory;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\PaymentsModule\Models\OneStopShop\OneStopShop;
use Crm\UsersModule\Repositories\UsersRepository;
use Nette\Application\BadRequestException;

class RequestsAdminPresenter extends AdminPresenter
{
    public function __construct(
        private RequestFormFactory $requestFormFactory,
        private UsersRepository $usersRepository,
        private FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        private OneStopShop $oneStopShop,
    ) {
        parent::__construct();
    }

    /**
     * @admin-access-level write
     */
    public function renderDefault($userId)
    {
        $user = $this->usersRepository->find($userId);
        if (!$user) {
            throw new BadRequestException('User not found');
        }

        $customFamilySubscriptionTypes = $this->familySubscriptionTypesRepository->getCustomizableSubscriptionTypes();

        $this->template->oneStopShopEnabled = $this->oneStopShop->isEnabled();
        $this->template->userRow = $user;
        $this->template->subscriptionTypes = $customFamilySubscriptionTypes;
    }

    public function createComponentRequestForm()
    {
        $userId = $this->getParameter('userId');
        $user = $this->usersRepository->find($userId);
        if (!$user) {
            throw new BadRequestException('User not found');
        }

        $form = $this->requestFormFactory->create($user);
        $this->requestFormFactory->onSave = function ($form, $user) {
            $this->flashMessage($this->translator->translate('payments.admin.payments.created'));
            $this->redirect(':Users:UsersAdmin:Show', $user->id);
        };

        return $form;
    }
}
