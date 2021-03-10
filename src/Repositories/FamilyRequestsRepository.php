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

    public function masterSubscriptionFamilyRequests(IRow $subscription): Selection
    {
        return $subscription->related('family_requests', 'master_subscription_id');
    }

    public function masterSubscriptionActiveFamilyRequests(IRow $subscription): Selection
    {
        return $this->masterSubscriptionFamilyRequests($subscription)->where('status', [self::STATUS_CREATED, self::STATUS_ACCEPTED]);
    }

    public function masterSubscriptionUnusedFamilyRequests(IRow $subscription): Selection
    {
        return $this->masterSubscriptionFamilyRequests($subscription)->where('status', self::STATUS_CREATED);
    }

    public function masterSubscriptionAcceptedFamilyRequests(IRow $subscription): Selection
    {
        return $this->getTable()
            ->where([
                'master_subscription_id' => $subscription->id,
                'status' => self::STATUS_ACCEPTED,
            ]);
    }

    public function masterSubscriptionCanceledFamilyRequests(IRow $subscription): Selection
    {
        return $this->masterSubscriptionFamilyRequests($subscription)->where('status', self::STATUS_CANCELED);
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
            'status' => self::STATUS_ACCEPTED
        ])->count('*') > 0;
    }

    final public function slaveUserFamilyRequests(IRow $user)
    {
        return $this->getTable()->where('slave_subscription.user_id', $user->id)
            ->order('slave_subscription.end_time DESC, slave_subscription.start_time DESC');
    }
}
