<?php

use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Nette\Utils\Random;
use Phinx\Migration\AbstractMigration;

class FamilySubscriptionsRequest extends AbstractMigration
{
    public function up()
    {
        $this->table('family_subscriptions')
            ->addColumn('family_request_id', 'integer', ['null' => true, 'after' => 'id'])
            ->update();

        // pair family_subscriptions with their family_requests
        $sql = <<<SQL
UPDATE family_subscriptions
JOIN subscriptions slave_subscription
  ON slave_subscription_id = slave_subscription.id
JOIN family_requests
  ON slave_subscription.user_id = family_requests.slave_user_id
  AND family_requests.master_subscription_id = family_subscriptions.master_subscription_id
  AND timediff(family_subscriptions.created_at, family_requests.accepted_at) BETWEEN -30 AND 30
SET family_subscriptions.family_request_id = family_requests.id
WHERE family_request_id IS NULL;
SQL;
        $this->execute($sql);

        // now if there are any remaining family subscriptions without linked request, they are mishandled
        // custom changes and we should treat them as past cancelled
        $result = $this->query("
SELECT family_subscriptions.*, slave_subscription.*, master_subscription.user_id AS master_user_id, family_subscriptions.created_at AS accepted_at
FROM family_subscriptions
JOIN subscriptions slave_subscription
  ON slave_subscription_id = slave_subscription.id
JOIN subscriptions master_subscription
  ON master_subscription_id = master_subscription.id
WHERE family_request_id IS NULL");

        foreach ($result as $record) {
            $this->table('family_requests')
                ->insert([
                    'master_user_id' => $record['master_user_id'],
                    'slave_user_id' => $record['user_id'],
                    'master_subscription_id' => $record['master_subscription_id'],
                    'status' => FamilyRequestsRepository::STATUS_CANCELED,
                    'code' => Random::generate(32),
                    'subscription_type_id' => $record['subscription_type_id'],
                    'created_at' => (new DateTime())->format('Y-m-d H:i:s'),
                    'updated_at' => (new DateTime())->format('Y-m-d H:i:s'),
                    'accepted_at' => $record['accepted_at'],
                    'canceled_at' => $record['end_time'],
                ])
                ->saveData();
        }

        $this->execute($sql);

        $this->table('family_subscriptions')
            ->changeColumn('family_request_id', 'integer', ['null' => false, 'after' => 'id'])
            ->addForeignKey('family_request_id', 'family_requests')
            ->update();
    }

    public function down()
    {
        $this->table('family_subscriptions')
            ->dropForeignKey('family_request_id')
            ->removeColumn('family_request_id')
            ->update();
    }
}
