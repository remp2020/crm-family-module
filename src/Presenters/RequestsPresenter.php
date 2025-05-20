<?php

namespace Crm\FamilyModule\Presenters;

use Crm\ApplicationModule\Helpers\MaskEmailHelper;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\ApplicationModule\UI\Form;
use Crm\FamilyModule\DataProviders\EmailFormDataProviderInterface;
use Crm\FamilyModule\Forms\EmailFormFactory;
use Crm\FamilyModule\Forms\SignInFormFactory;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\SubscriptionsModule\Models\Subscription\ActualUserSubscription;
use Crm\UsersModule\Models\Auth\Authorizator;
use Crm\UsersModule\Models\Auth\InvalidEmailException;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Repositories\UserActionsLogRepository;
use Nette\Application\BadRequestException;
use Nette\DI\Attributes\Inject;
use Nette\Database\Table\ActiveRow;
use Nette\Security\AuthenticationException;
use Nette\Utils\DateTime;
use Tomaj\Form\Renderer\BootstrapInlineRenderer;

class RequestsPresenter extends FrontendPresenter
{
    #[Inject]
    public FamilyRequestsRepository $familyRequestsRepository;

    #[Inject]
    public UserManager $userManager;

    #[Inject]
    public Authorizator $authorizator;

    #[Inject]
    public DonateSubscription $donateSubscription;

    #[Inject]
    public ActualUserSubscription $actualUserSubscription;

    #[Inject]
    public UserActionsLogRepository $userActionsLogRepository;

    #[Inject]
    public DataProviderManager $dataProviderManager;

    #[Inject]
    public EmailFormFactory $emailFormFactory;

    #[Inject]
    public SignInFormFactory $signInFormFactory;

    public function renderDefault($id)
    {
        if ($this->getUser()->isLoggedIn()) {
            $this->redirect('logged', $id);
        }
        if (!$id) {
            throw new BadRequestException();
        }
        $request = $this->familyRequest($id);
        $this->template->request = $request;
    }

    public function renderNew()
    {
        $isLogged = $this->getUser()->isLoggedIn();
        $this->template->isLogged = $isLogged;
        if ($isLogged) {
            $hasSubscription = $this->actualUserSubscription->hasActual();
            $this->template->hasSubscription = $hasSubscription;
        }
    }

    public function renderOnlyLogged()
    {
        $this->onlyLoggedIn();
        $this->redirect('new');
    }

    public function renderLogged($id)
    {
        $this->onlyLoggedIn();
        if (!$id) {
            throw new BadRequestException();
        }
        $request = $this->familyRequest($id);
        $this->template->request = $request;
    }

    public function renderSignIn($id, $email)
    {
        if ($this->getUser()->isLoggedIn()) {
            $this->redirect('logged', $id);
        }
        if (!$id) {
            throw new BadRequestException();
        }
        $request = $this->familyRequest($id);
        $this->template->request = $request;
        $this->template->email = $email;
    }

    private function familyRequest(string $id): ActiveRow
    {
        $familyRequest = $this->familyRequestsRepository->findByCode($id);

        if (!$familyRequest) {
            $this->redirect('missing');
        }
        if ($familyRequest->status == FamilyRequestsRepository::STATUS_ACCEPTED) {
            $this->redirect('accepted', $id);
        }
        if ($familyRequest->status == FamilyRequestsRepository::STATUS_CANCELED) {
            $this->redirect('canceled');
        }
        if ($familyRequest->master_subscription->end_time <= new DateTime()) {
            $this->redirect('expired');
        }
        if ($familyRequest->expires_at && $familyRequest->expires_at <= new DateTime()) {
            $this->redirect('expired');
        }
        if ($familyRequest->opened_at === null) {
            $this->familyRequestsRepository->update($familyRequest, ['opened_at' => new DateTime()]);
        }

        return $familyRequest;
    }

    public function renderAccepted($id)
    {
        if (!$id) {
            throw new BadRequestException();
        }
        $familyRequest = $this->familyRequestsRepository->findByCode($id);
        if (!$familyRequest || $familyRequest->status != FamilyRequestsRepository::STATUS_ACCEPTED) {
            $this->redirect('error');
        }
        if ($familyRequest->slave_user->id == $this->getUser()->getId()) {
            $this->redirect('success');
        }

        $this->template->email = (new MaskEmailHelper())->process($familyRequest->slave_user->email);
        $this->template->date = $familyRequest->accepted_at;
    }

    public function renderError($message)
    {
        $this->template->message = $message;
    }

    public function renderSuccess($from)
    {
        $this->template->from = $from;
    }

    protected function createComponentConfirmRequest()
    {
        $email = $this->userManager->loadUser($this->getUser())->email;

        $form = new Form();
        $form->setTranslator($this->translator);
        $form->setRenderer(new BootstrapInlineRenderer());
        $form->addHidden('request', $this->params['id']);
        $form->addSubmit('send', $this->translator->translate('family.frontend.logged_in.activate', [
            'email' => $email,
        ]));

        $form->onSuccess[] = function (Form $form) {
            $id = $form->getValues()['request'];
            $request = $this->familyRequest($id);
            $userRow = $this->userManager->loadUser($this->getUser());

            $result = $this->donateSubscription->connectFamilyUser($userRow, $request);
            if ($result == DonateSubscription::ERROR_INTERNAL) {
                $this->userActionsLogRepository->add($this->getUser()->id, 'family.logged.error.internal', ['request' => $id]);
                $this->redirect('error', ['message' => 'internal']);
            } elseif ($result == DonateSubscription::ERROR_IN_USE) {
                $this->userActionsLogRepository->add($this->getUser()->id, 'family.logged.error.in-use', ['request' => $id]);
                $this->redirect('error', ['message' => 'in-use']);
            } elseif ($result == DonateSubscription::ERROR_SELF_USE) {
                $this->userActionsLogRepository->add($this->getUser()->id, 'family.logged.error.self-use', ['request' => $id]);
                $this->redirect('error', ['message' => 'self']);
            } elseif ($result == DonateSubscription::ERROR_MASTER_SUBSCRIPTION_EXPIRED) {
                $this->userActionsLogRepository->add($this->getUser()->id, 'family.register.error.master-subscription-expired', (array) $form->getValues());
                $this->redirect('expired');
            } else {
                $this->redirect('success');
            }
        };
        return $form;
    }

    protected function createComponentEmailForm()
    {
        $form = $this->emailFormFactory->create();
        $form->addHidden('request', $this->params['id']);

        $form->onSuccess[] = function (Form $form) {
            if ($this->userManager->loadUserByEmail($form->getValues()['email'])) {
                $this->redirect('signIn', $form->getValues()['request'], $form->getValues()['email']);
            }
            try {
                $user = $this->userManager->addNewUser($form->getValues()['email'], true, 'family');
            } catch (InvalidEmailException $e) {
                $form->addError('family.frontend.new.form.error_email');
                return;
            }
            $this->getUser()->login(['user' => $user, 'autoLogin' => true]);

            /** @var EmailFormDataProviderInterface[] $providers */
            $providers = $this->dataProviderManager->getProviders('family.dataprovider.email_form', EmailFormDataProviderInterface::class);
            foreach ($providers as $sorting => $provider) {
                $form = $provider->submit($user, $form);
            }

            $request = $this->familyRequest($form->getValues()['request']);
            $result = $this->donateSubscription->connectFamilyUser($user, $request);

            if ($result == DonateSubscription::ERROR_INTERNAL) {
                $this->userActionsLogRepository->add($this->getUser()->id, 'family.register.error.internal', (array) $form->getValues());
                $this->redirect('error', ['message' => 'internal']);
            } elseif ($result == DonateSubscription::ERROR_IN_USE) {
                $this->userActionsLogRepository->add($this->getUser()->id, 'family.register.error.in-use', (array) $form->getValues());
                $this->redirect('error', ['message' => 'in-use']);
            } elseif ($result == DonateSubscription::ERROR_MASTER_SUBSCRIPTION_EXPIRED) {
                $this->userActionsLogRepository->add($this->getUser()->id, 'family.register.error.master-subscription-expired', (array) $form->getValues());
                $this->redirect('expired');
            } else {
                $this->redirect('success', ['from' => 'new']);
            }
        };
        return $form;
    }

    protected function createComponentSignInForm()
    {
        $form = $this->signInFormFactory->create($this->params['email']);
        $form->addHidden('request', $this->params['id']);

        $form->onSuccess[] = function (Form $form) {
            try {
                $this->getUser()->login(['username' => $form->getValues()['username'], 'password' => $form->getValues()['password']]);
                $this->getUser()->setAuthorizator($this->authorizator);

                $user = $this->userManager->loadUser($this->getUser());
                $request = $this->familyRequest($form->getValues()['request']);

                $result = $this->donateSubscription->connectFamilyUser($user, $request);

                if ($result == DonateSubscription::ERROR_INTERNAL) {
                    $this->userActionsLogRepository->add($this->getUser()->id, 'family.register.error.internal', (array)$form->getValues());
                    $this->redirect('error', ['message' => 'internal']);
                } elseif ($result == DonateSubscription::ERROR_IN_USE) {
                    $this->userActionsLogRepository->add($this->getUser()->id, 'family.register.error.in-use', (array)$form->getValues());
                    $this->redirect('error', ['message' => 'in-use']);
                } elseif ($result == DonateSubscription::ERROR_MASTER_SUBSCRIPTION_EXPIRED) {
                    $this->userActionsLogRepository->add($this->getUser()->id, 'family.register.error.master-subscription-expired', (array) $form->getValues());
                    $this->redirect('expired');
                } else {
                    /** @var EmailFormDataProviderInterface[] $providers */
                    $providers = $this->dataProviderManager->getProviders('family.dataprovider.email_form', EmailFormDataProviderInterface::class);
                    foreach ($providers as $sorting => $provider) {
                        $form = $provider->submit($user, $form);
                    }

                    $this->redirect('success', ['from' => 'sign']);
                }
            } catch (AuthenticationException $e) {
                $form->addError($e->getMessage());
            }
        };
        return $form;
    }
}
