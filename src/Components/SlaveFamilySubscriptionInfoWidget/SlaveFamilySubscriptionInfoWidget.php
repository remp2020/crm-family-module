<?php


namespace Crm\FamilyModule\Components\SlaveFamilySubscriptionInfoWidget;

use Crm\ApplicationModule\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Widget\LazyWidgetManager;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\UsersModule\Repositories\UsersRepository;

class SlaveFamilySubscriptionInfoWidget extends BaseLazyWidget
{
    private $templateName = 'slave_family_subscription_info_widget.latte';

    private $familyRequestsRepository;

    private $usersRepository;

    public function __construct(
        LazyWidgetManager $lazyWidgetManager,
        FamilyRequestsRepository $familyRequestsRepository,
        UsersRepository $usersRepository
    ) {
        parent::__construct($lazyWidgetManager);

        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->usersRepository = $usersRepository;
    }

    public function identifier()
    {
        return 'slavefamilysubscriptioninfowidget';
    }

    public function render(int $userId)
    {
        $user = $this->usersRepository->find($userId);
        $userSlaveFamilyRequests = $this->familyRequestsRepository->slaveUserFamilyRequests($user)
            ->where('status', FamilyRequestsRepository::STATUS_ACCEPTED);

        if (count($userSlaveFamilyRequests) === 0) {
            return;
        }

        $this->template->familyRequests = $userSlaveFamilyRequests;

        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }
}
