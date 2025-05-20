<?php

namespace Crm\FamilyModule\Components\FamilyRequestsListWidget;

use Crm\ApplicationModule\Models\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManager;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Models\MissingFamilySubscriptionTypeException;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Nette\Localization\Translator;
use Nette\Security\User;
use Tracy\Debugger;

class FamilyRequestsListWidget extends BaseLazyWidget
{
    private $templateName = 'family_requests_list_widget.latte';

    private $familyRequests;

    private $familyRequestsRepository;

    private $subscriptionsRepository;

    private $user;

    private $translator;

    public function __construct(
        LazyWidgetManager $lazyWidgetManager,
        User $user,
        FamilyRequests $familyRequests,
        FamilyRequestsRepository $familyRequestsRepository,
        SubscriptionsRepository $subscriptionsRepository,
        Translator $translator,
    ) {
        parent::__construct($lazyWidgetManager);
        $this->familyRequests = $familyRequests;
        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->user = $user;
        $this->translator = $translator;
    }

    public function header($id = '')
    {
        return 'Family requests list widget';
    }

    public function identifier()
    {
        return 'familyrequestslistwidget';
    }

    public function render($id = '')
    {
        $requests = [];

        foreach ($this->subscriptionsRepository->actualUserSubscriptions($this->user->getId()) as $activeSubscription) {
            // create requests if needed
            try {
                $this->familyRequests->createFromSubscription($activeSubscription);
            } catch (MissingFamilySubscriptionTypeException $exception) {
                // everything all right, we don't want to create family requests if meta is missing
                continue;
            } catch (\Exception $exception) {
                Debugger::log($exception, Debugger::EXCEPTION);
                $this->flashMessage(
                    $this->translator->translate(
                        'family.frontend.family_requests.failed_to_generate',
                        ['subscription_id' => $activeSubscription->id],
                    ),
                    'error',
                );
                continue;
            }

            $subscriptionRequests = $this->familyRequestsRepository
                ->masterSubscriptionFamilyRequests($activeSubscription)->fetchAll();
            $requests = array_merge($requests, $subscriptionRequests);
        }

        if (!$requests) {
            return;
        }

        $this->template->requests = $requests;
        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }
}
