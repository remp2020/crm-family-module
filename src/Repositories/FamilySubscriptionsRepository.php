<?php

namespace Crm\FamilyModule\Repositories;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\IRow;
use Nette\Utils\DateTime;

class FamilySubscriptionsRepository extends Repository
{
    const TYPE_SINGLE = 'single';

    protected $tableName = 'family_subscriptions';

    public function add(IRow $masterSubscription, IRow $slaveSubscription, $type)
    {
        return $this->getTable()->insert([
            'master_subscription_id' => $masterSubscription->id,
            'slave_subscription_id' => $slaveSubscription->id,
            'type' => $type,
            'created_at' => new DateTime(),
        ]);
    }

    public function findByMasterSubscription(IRow $masterSubscription)
    {
        return $this->getTable()->where(['master_subscription_id' => $masterSubscription->id])->fetchAll();
    }

    public function hasActiveSlaveSubscription(IRow $masterSubscription)
    {
        return $this->getTable()->where(['master_subscription_id' => $masterSubscription->id, 'slave_subscription.start_time < ?' => new DateTime(), 'slave_subscription.end_time > ?' => new DateTime()])->count('*') > 0;
    }

    public function isSlaveSubscription(IRow $subscription)
    {
        return $this->getTable()->where(['slave_subscription_id' => $subscription->id])->count('*') > 0;
    }
}
