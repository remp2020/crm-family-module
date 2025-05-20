<?php

namespace Crm\FamilyModule\Components\UsersAbusiveAdditionalWidget;

use Crm\ApplicationModule\Models\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManager;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class UsersAbusiveAdditionalWidget extends BaseLazyWidget
{
    private $templateName = 'users_abusive_additional_widget.latte';

    private $familyRequestsRepository;

    private $familySubscriptionTypesRepository;

    public function __construct(
        LazyWidgetManager $lazyWidgetManager,
        FamilyRequestsRepository $familyRequestsRepository,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
    ) {
        parent::__construct($lazyWidgetManager);
        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
    }

    public function identifier()
    {
        return 'usersabusiveadditionalwidget';
    }

    public function render(ActiveRow $user)
    {
        $params = $this->getPresenter()->getParameters();

        $startTime = false;
        if (isset($params['start_time'])) {
            $startTime = DateTime::createFromFormat('Y-m-d', $params['start_time']);
        }
        $endTime = false;
        if (isset($params['end_time'])) {
            $endTime = DateTime::createFromFormat('Y-m-d', $params['end_time']);
        }

        $familyRequests = $this->familyRequestsRepository
            ->userFamilyRequest($user)
            ->where('master_subscription.subscription_type_id IN (?)', $this->familySubscriptionTypesRepository->masterSubscriptionTypes());

        if ($startTime && $endTime) {
            $familyRequests
                ->where('family_requests.created_at < ?', $endTime)
                ->where('master_subscription.end_time > ', $startTime)
                ->where('master_subscription.start_time < ', $endTime);
        }

        $familyRequestsCount = (clone $familyRequests)->count();
        $this->template->familyRequestsAll = $familyRequestsCount;

        $familyRequestsStatuses = (clone $familyRequests)->group('status')->select('status, count(*) AS cnt')->fetchAssoc('status');
        $this->template->familyRequestsUnused = $familyRequestsStatuses[FamilyRequestsRepository::STATUS_CREATED]['cnt'] ?? 0;
        $this->template->familyRequestsAccepted = $familyRequestsStatuses[FamilyRequestsRepository::STATUS_ACCEPTED]['cnt'] ?? 0;
        $this->template->familyRequestsCanceled = $familyRequestsStatuses[FamilyRequestsRepository::STATUS_CANCELED]['cnt'] ?? 0;

        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }
}
