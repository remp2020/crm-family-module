<?php

namespace Crm\FamilyModule\Repositories;

use Crm\ApplicationModule\Models\Database\Repository;
use Nette\Database\Table\ActiveRow;

class FamilySubscriptionTypesRepository extends Repository
{
    protected $tableName = 'family_subscription_types';

    final public function add(ActiveRow $masterSubscriptionType, ?ActiveRow $slaveSubscriptionType, $donationMethod, $count, bool $isPaid = false)
    {
        return $this->getTable()->insert([
            'master_subscription_type_id' => $masterSubscriptionType->id,
            'slave_subscription_type_id' => $slaveSubscriptionType->id ?? null,
            'donation_method' => $donationMethod,
            'count' => $count,
            'is_paid' => $isPaid,
        ]);
    }

    final public function findByMasterSubscriptionType(ActiveRow $subscriptionType)
    {
        return $this->getTable()->where(['master_subscription_type_id' => $subscriptionType->id])->fetch();
    }

    final public function findBySlaveSubscriptionType(ActiveRow $subscriptionType)
    {
        return $this->getTable()->where(['slave_subscription_type_id' => $subscriptionType->id])->fetchAll();
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

    final public function isMasterSubscriptionType(ActiveRow $subscriptionType): bool
    {
        return $this->getTable()->where('master_subscription_type_id', $subscriptionType->id)->count('*') > 0;
    }

    final public function isSlaveSubscriptionType(ActiveRow $subscriptionType): bool
    {
        return $this->getTable()->where('slave_subscription_type_id', $subscriptionType->id)->count('*') > 0;
    }

    final public function getCustomizableSubscriptionTypes(): array
    {
        return $this->getTable()->where(['slave_subscription_type_id' => null])
            ->fetchAll();
    }
}
