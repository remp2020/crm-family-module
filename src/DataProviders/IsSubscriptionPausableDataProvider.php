<?php
declare(strict_types=1);

namespace Crm\FamilyModule\DataProviders;

use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\PaymentsModule\DataProviders\IsSubscriptionPausableDataProviderInterface;
use Nette\Database\Table\ActiveRow;

class IsSubscriptionPausableDataProvider implements IsSubscriptionPausableDataProviderInterface
{
    public function __construct(
        private readonly FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
    ) {
    }

    public function isSubscriptionPausable(ActiveRow $subscription): bool
    {
        if ($this->familySubscriptionTypesRepository->isFamilySubscriptionType($subscription->subscription_type)) {
            return false;
        }

        return true;
    }
}
