<?php

declare(strict_types=1);

namespace Crm\FamilyModule\DataProviders;

use Crm\ApplicationModule\Models\DataProvider\DataProviderException;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\DataProviders\SubscriptionTransferDataProviderInterface;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\ArrayHash;

class SubscriptionTransferDataProvider implements SubscriptionTransferDataProviderInterface
{
    public function __construct(
        private readonly FamilyRequestsRepository $familyRequestsRepository,
        private readonly FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function provide(array $params): void
    {
    }

    public function transfer(ActiveRow $subscription, ActiveRow $userToTransferTo, ArrayHash $formData): void
    {
        if (!$this->isTransferable($subscription)) {
            // this should never happen, as a back-end should check transferability before calling providers
            throw new DataProviderException('Subscription is not transferable');
        }

        $familyRequests = $this->familyRequestsRepository->masterSubscriptionFamilyRequests($subscription);
        foreach ($familyRequests as $familyRequest) {
            $this->familyRequestsRepository->update($familyRequest, ['master_user_id' => $userToTransferTo->id]);
        }
    }

    public function isTransferable(ActiveRow $subscription): bool
    {
        $isSlaveSubscription = $this->familySubscriptionTypesRepository->getTable()
            ->where(['slave_subscription_type_id' => $subscription->subscription_type_id])
            ->count('*') > 0;

        if ($isSlaveSubscription) {
            return false;
        }

        return true;
    }
}
