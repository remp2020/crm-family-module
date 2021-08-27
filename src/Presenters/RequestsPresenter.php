<?php

namespace Crm\FamilyModule\Presenters;

use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\FamilyModule\DataProviders\EmailFormDataProviderInterface;
use Crm\FamilyModule\Helpers\MaskEmailHelper;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\SubscriptionsModule\Subscription\ActualUserSubscription;
use Crm\UsersModule\Auth\Authorizator;
use Crm\UsersModule\Auth\InvalidEmailException;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Repository\UserActionsLogRepository;
use Nette\Application\UI\Form;
use Nette\Security\AuthenticationException;
use Nette\Utils\DateTime;
use Tomaj\Form\Renderer\BootstrapInlineRenderer;
use Tomaj\Form\Renderer\BootstrapRenderer;

class RequestsPresenter extends FrontendPresenter
{
    private $familyRequestsRepository;

    private $userManager;

    private $authorizator;

    private $donateSubscription;

    private $actualUserSubscription;

    private $userActionsLogRepository;

    private $dataProviderManager;

    public function __construct(
        FamilyRequestsRepository $familyRequestsRepository,
        UserManager $userManager,
        Authorizator $authorizator,
        DonateSubscription $donateSubscription,
        ActualUserSubscription $actualUserSubscription,
        UserActionsLogRepository $userActionsLogRepository,
        DataProviderManager $dataProviderManager
    ) {
        parent::__construct();
        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->userManager = $userManager;
        $this->authorizator = $authorizator;
        $this->donateSubscription = $donateSubscription;
        $this->actualUserSubscription = $actualUserSubscription;
        $this->userActionsLogRepository = $userActionsLogRepository;
        $this->dataProviderManager = $dataProviderManager;
    }

    public function renderDefault($id)
    {
        if ($this->getUser()->isLoggedIn()) {
            $this->redirect('logged', $id);
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
        $request = $this->familyRequest($id);
        $this->template->request = $request;
    }

    public function renderSignIn($id, $email)
    {
        if ($this->getUser()->isLoggedIn()) {
            $this->redirect('logged', $id);
        }
        $request = $this->familyRequest($id);
        $this->template->request = $request;
        $this->template->email = $email;
    }

    private function familyRequest($id)
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
            'email' => $email
        ]));

        $form->onSuccess[] = function (Form $form) {
            $id = $form->values['request'];
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
                $this->userActionsLogRepository->add($this->getUser()->id, 'family.register.error.master-subscription-expired', (array) $form->values);
                $this->redirect('expired');
            } else {
                $this->redirect('success');
            }
        };
        return $form;
    }

    protected function createComponentEmailForm()
    {
        $form = new Form();
        $form->setTranslator($this->translator);
        $form->setRenderer(new BootstrapRenderer());
        $form->addText('email', 'family.frontend.new.form.email')
            ->setHtmlAttribute('autofocus')
            ->setRequired('family.frontend.new.form.email_required')
            ->setHtmlAttribute('placeholder', 'family.frontend.new.form.email_placeholder');

        /** @var EmailFormDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders('family.dataprovider.email_form', EmailFormDataProviderInterface::class);
        foreach ($providers as $sorting => $provider) {
            $form = $provider->provide(['form' => $form]);
        }

        $form->addHidden('request', $this->params['id']);
        $form->addSubmit('submit', 'family.frontend.new.form.submit');
        $form->onSuccess[] = function (Form $form) {
            if ($this->userManager->loadUserByEmail($form->values['email'])) {
                $this->redirect('signIn', $form->values['request'], $form->values['email']);
            }
            try {
                $user = $this->userManager->addNewUser($form->values['email'], true, 'family');
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

            $request = $this->familyRequest($form->values['request']);
            $result = $this->donateSubscription->connectFamilyUser($user, $request);

            if ($result == DonateSubscription::ERROR_INTERNAL) {
                $this->userActionsLogRepository->add($this->getUser()->id, 'family.register.error.internal', (array) $form->values);
                $this->redirect('error', ['message' => 'internal']);
            } elseif ($result == DonateSubscription::ERROR_IN_USE) {
                $this->userActionsLogRepository->add($this->getUser()->id, 'family.register.error.in-use', (array) $form->values);
                $this->redirect('error', ['message' => 'in-use']);
            } elseif ($result == DonateSubscription::ERROR_MASTER_SUBSCRIPTION_EXPIRED) {
                $this->userActionsLogRepository->add($this->getUser()->id, 'family.register.error.master-subscription-expired', (array) $form->values);
                $this->redirect('expired');
            } else {
                $this->redirect('success', ['from' => 'new']);
            }
        };
        return $form;
    }

    protected function createComponentSignInForm()
    {
        $form = new Form();
        $form->setTranslator($this->translator);
        $form->setRenderer(new BootstrapRenderer());
        $form->addHidden('request', $this->params['id']);
        $form->addText('username', 'family.frontend.new.form.email')
            ->setHtmlType('email')
            ->setHtmlAttribute('autofocus')
            ->setRequired('family.frontend.new.form.email_required')
            ->setHtmlAttribute('placeholder', 'family.frontend.new.form.email_placeholder');

        $form->addPassword('password', 'family.frontend.signin.form.password')
            ->setRequired('family.frontend.signin.form.password_required')
            ->setHtmlAttribute('placeholder', 'family.frontend.signin.form.password_placeholder');

        $form->addSubmit('send', 'family.frontend.signin.form.submit');

        $form->setDefaults(['username' => $this->params['email']]);

        $form->onSuccess[] = function (Form $form) {
            try {
                $this->getUser()->login(['username' => $form->values['username'], 'password' => $form->values['password']]);
                $this->getUser()->setAuthorizator($this->authorizator);

                $user = $this->userManager->loadUser($this->getUser());
                $request = $this->familyRequest($form->values['request']);

                $result = $this->donateSubscription->connectFamilyUser($user, $request);

                if ($result == DonateSubscription::ERROR_INTERNAL) {
                    $this->userActionsLogRepository->add($this->getUser()->id, 'family.register.error.internal', (array)$form->values);
                    $this->redirect('error', ['message' => 'internal']);
                } elseif ($result == DonateSubscription::ERROR_IN_USE) {
                    $this->userActionsLogRepository->add($this->getUser()->id, 'family.register.error.in-use', (array)$form->values);
                    $this->redirect('error', ['message' => 'in-use']);
                } elseif ($result == DonateSubscription::ERROR_MASTER_SUBSCRIPTION_EXPIRED) {
                    $this->userActionsLogRepository->add($this->getUser()->id, 'family.register.error.master-subscription-expired', (array) $form->values);
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
