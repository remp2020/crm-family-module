<?php

namespace Crm\FamilyModule\Repositories;

use Crm\ApplicationModule\Repository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Nette\Database\Context;
use Nette\Database\Table\IRow;
use Nette\Database\Table\Selection;
use Nette\Utils\DateTime;
use Nette\Utils\Random;

class FamilyRequestsRepository extends Repository
{
    const STATUS_CREATED = 'created';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_CANCELED = 'canceled';

    protected $tableName = 'family_requests';

    private $familySubscriptionTypesRepository;

    private $subscriptionsRepository;

    public function __construct(
        Context $database,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        SubscriptionsRepository $subscriptionsRepository
    ) {
        parent::__construct($database);

        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
    }

    public function add(IRow $subscription, IRow $subscriptionType, $status = self::STATUS_CREATED, DateTime $expiresAt = null)
    {
        return $this->getTable()->insert([
            'master_user_id' => $subscription->user_id,
            'subscription_type_id' => $subscriptionType,
            'master_subscription_id' => $subscription->id,
            'status' => $status,
            'code' => Random::generate(32),
            'created_at' => new DateTime(),
            'updated_at' => new DateTime(),
            'expires_at' => $expiresAt,
        ]);
    }

    public function findByCode($code)
    {
        return $this->getTable()->where(['code' => $code])->limit(1)->fetch();
    }

    public function userFamilyRequest(IRow $user)
    {
        return $this->getTable()->where(['master_user_id' => $user->id]);
    }

    public function masterSubscriptionFamilyRequest(IRow $subscription): Selection
    {
        return $this->getTable()->where(['master_subscription_id' => $subscription->id]);
    }

    public function masterSubscriptionUsedFamilyRequests(IRow $subscription): Selection
    {
        return $this->masterSubscriptionFamilyRequest($subscription)->where([
            'status NOT' => [self::STATUS_CREATED],
        ]);
    }

    public function masterSubscriptionUnusedFamilyRequests(IRow $subscription): Selection
    {
        return $this->getTable()
            ->where([
                'master_subscription_id' => $subscription->id,
                'status' => self::STATUS_CREATED,
            ]);
    }

    public function cancelCreatedRequests(IRow $user)
    {
        return $this->getTable()->where(['master_user_id' => $user->id, 'status' => self::STATUS_CREATED])->update([
            'status' => self::STATUS_CANCELED,
            'canceled_at' => new DateTime(),
        ]);
    }

    public function userAlreadyHasSubscriptionFrom(IRow $masterSubscription, IRow $user)
    {
        return $this->getTable()->where([
            'master_subscription_id' => $masterSubscription->id,
            'slave_user_id' => $user->id,
            'status' => FamilyRequestsRepository::STATUS_ACCEPTED
        ])->count('*') > 0;
    }

    final public function userMasterSubscriptions(IRow $user)
    {
        return $this->subscriptionsRepository->userSubscriptions($user->id)
            ->where('subscription_type_id IN ?', $this->familySubscriptionTypesRepository->masterSubscriptionTypes());
    }

    final public function findByMasterSubscriptionSlaveUser(IRow $familySubscription)
    {
        return $this->getTable()->where([
            'master_subscription_id' => $familySubscription->master_subscription_id,
            'slave_user_id' => $familySubscription->slave_subscription->user_id
        ])->fetch();
    }
}
