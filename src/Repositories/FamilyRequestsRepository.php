<?php

namespace Crm\FamilyModule\Repositories;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;
use Nette\Utils\DateTime;
use Nette\Utils\Random;

class FamilyRequestsRepository extends Repository
{
    const STATUS_CREATED = 'created';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_CANCELED = 'canceled';

    protected $tableName = 'family_requests';

    public function add(
        ActiveRow $subscription,
        ActiveRow $subscriptionType,
        $status = self::STATUS_CREATED,
        ?DateTime $expiresAt = null,
        ?string $note = null
    ) {
        return $this->getTable()->insert([
            'master_user_id' => $subscription->user_id,
            'subscription_type_id' => $subscriptionType,
            'master_subscription_id' => $subscription->id,
            'status' => $status,
            'code' => Random::generate(32),
            'created_at' => new DateTime(),
            'updated_at' => new DateTime(),
            'expires_at' => $expiresAt,
            'note' => $note,
        ]);
    }

    public function findByCode($code)
    {
        return $this->getTable()->where(['code' => $code])->limit(1)->fetch();
    }

    public function userFamilyRequest(ActiveRow $user)
    {
        return $this->getTable()->where(['master_user_id' => $user->id]);
    }

    public function masterSubscriptionFamilyRequests(ActiveRow $subscription): Selection
    {
        return $subscription->related('family_requests', 'master_subscription_id');
    }

    public function masterSubscriptionActiveFamilyRequests(ActiveRow $subscription): Selection
    {
        return $this->masterSubscriptionFamilyRequests($subscription)->where('status', [self::STATUS_CREATED, self::STATUS_ACCEPTED]);
    }

    public function masterSubscriptionUnusedFamilyRequests(ActiveRow $subscription): Selection
    {
        return $this->masterSubscriptionFamilyRequests($subscription)->where('status', self::STATUS_CREATED);
    }

    public function masterSubscriptionAcceptedFamilyRequests(ActiveRow $subscription): Selection
    {
        return $this->getTable()
            ->where([
                'master_subscription_id' => $subscription->id,
                'status' => self::STATUS_ACCEPTED,
            ]);
    }

    public function masterSubscriptionCanceledFamilyRequests(ActiveRow $subscription): Selection
    {
        return $this->masterSubscriptionFamilyRequests($subscription)->where('status', self::STATUS_CANCELED);
    }

    public function cancelCreatedRequests(ActiveRow $user)
    {
        return $this->getTable()->where(['master_user_id' => $user->id, 'status' => self::STATUS_CREATED])->update([
            'status' => self::STATUS_CANCELED,
            'canceled_at' => new DateTime(),
        ]);
    }

    public function userAlreadyHasSubscriptionFrom(ActiveRow $masterSubscription, ActiveRow $user)
    {
        return $this->getTable()->where([
            'master_subscription_id' => $masterSubscription->id,
            'slave_user_id' => $user->id,
            'status' => self::STATUS_ACCEPTED
        ])->count('*') > 0;
    }

    final public function slaveUserFamilyRequests(ActiveRow $user)
    {
        return $this->getTable()->where('slave_subscription.user_id', $user->id)
            ->order('slave_subscription.end_time DESC, slave_subscription.start_time DESC');
    }

    final public function findSlaveSubscriptionFamilyRequest(ActiveRow $subscription)
    {
        return $this->getTable()->where('slave_subscription_id', $subscription->id)->fetch();
    }
}
