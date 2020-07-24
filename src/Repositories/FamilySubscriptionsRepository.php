<?php

namespace Crm\FamilyModule\Repositories;

use Crm\ApplicationModule\Repository;
use Nette\Database\Context;
use Nette\Database\Table\IRow;
use Nette\Utils\DateTime;

class FamilySubscriptionsRepository extends Repository
{
    const TYPE_SINGLE = 'single';

    protected $tableName = 'family_subscriptions';

    private $familyRequestsRepository;

    public function __construct(
        Context $database,
        FamilyRequestsRepository $familyRequestsRepository
    ) {
        parent::__construct($database);

        $this->familyRequestsRepository = $familyRequestsRepository;
    }

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

    public function findActiveUserSlaveFamilySubscriptions(IRow $user)
    {
        $familySubscriptions = [];
        foreach ($this->findUserSlaveFamilySubscriptions($user) as $familySubscription) {
            $relatedFamilyRequest = $this->familyRequestsRepository->findByMasterSubscriptionSlaveUser($familySubscription);
            if ($relatedFamilyRequest->status == FamilyRequestsRepository::STATUS_ACCEPTED) {
                $familySubscriptions[] = $familySubscription;
            }
        }
        return $familySubscriptions;
    }

    public function findUserSlaveFamilySubscriptions($user)
    {
        return $this->getTable()->where('slave_subscription.user_id', $user->id)
          ->order('slave_subscription.end_time DESC, slave_subscription.start_time DESC');
    }
}
