<?php

namespace Crm\FamilyModule\Components\FamilyRequestsListWidget;

use Crm\ApplicationModule\Widget\BaseWidget;
use Crm\ApplicationModule\Widget\WidgetManager;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Models\MissingFamilySubscriptionTypeException;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Nette\Security\User;
use Tracy\Debugger;

class FamilyRequestsListWidget extends BaseWidget
{
    private $templateName = 'family_requests_list_widget.latte';

    private $familyRequests;

    private $familyRequestsRepository;

    private $subscriptionsRepository;

    private $user;

    public function __construct(
        WidgetManager $widgetManager,
        User $user,
        FamilyRequests $familyRequests,
        FamilyRequestsRepository $familyRequestsRepository,
        SubscriptionsRepository $subscriptionsRepository
    ) {
        parent::__construct($widgetManager);
        $this->familyRequests = $familyRequests;
        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->user = $user;
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
                continue;
            }

            $subscriptionRequests = $this->familyRequestsRepository
                ->masterSubscriptionFamilyRequest($activeSubscription)->fetchAll();
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
