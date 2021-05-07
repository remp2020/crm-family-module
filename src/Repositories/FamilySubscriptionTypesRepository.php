<?php

namespace Crm\FamilyModule\Repositories;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\IRow;

class FamilySubscriptionTypesRepository extends Repository
{
    protected $tableName = 'family_subscription_types';

    final public function add(IRow $masterSubscriptionType, IRow $slaveSubscriptionType, $donationMethod, $count, bool $isPaid = false)
    {
        return $this->getTable()->insert([
            'master_subscription_type_id' => $masterSubscriptionType->id,
            'slave_subscription_type_id' => $slaveSubscriptionType->id,
            'donation_method' => $donationMethod,
            'count' => $count,
            'is_paid' => $isPaid,
        ]);
    }

    final public function findByMasterSubscriptionType(IRow $subscriptionType)
    {
        return $this->getTable()->where(['master_subscription_type_id' => $subscriptionType->id])->fetch();
    }

    /**
     * Return all company/family parent subscription types.
     */
    final public function masterSubscriptionTypes(): array
    {
        return $this->getTable()->fetchAssoc('master_subscription_type_id=master_subscription_type_id');
    }

    /**
     * Return all company/family child subscription types.
     */
    final public function slaveSubscriptionTypes(): array
    {
        return $this->getTable()->fetchAssoc('slave_subscription_type_id=slave_subscription_type_id');
    }

    final public function isMasterSubscriptionType(IRow $subscriptionType): bool
    {
        return $this->getTable()->where('master_subscription_type_id', $subscriptionType->id)->count('*') > 0;
    }

    final public function isSlaveSubscriptionType(IRow $subscriptionType): bool
    {
        return $this->getTable()->where('slave_subscription_type_id', $subscriptionType->id)->count('*') > 0;
    }
}
