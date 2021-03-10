<?php

use Phinx\Migration\AbstractMigration;

class MoveSlaveSubscriptionToFamilyRequests extends AbstractMigration
{
    public function up()
    {
        $this->table('family_requests')
            ->addColumn('slave_subscription_id', 'integer', ['null' => true, 'after' => 'master_subscription_id'])
            ->addForeignKey('slave_subscription_id', 'subscriptions')
            ->update();

        // pair family_subscriptions with their family_requests
        $sql = <<<SQL
UPDATE family_requests
JOIN family_subscriptions
  ON family_subscriptions.family_request_id = family_requests.id
SET family_requests.slave_subscription_id = family_subscriptions.slave_subscription_id
SQL;
        $this->execute($sql);
    }

    public function down()
    {
        $this->table('family_requests')
            ->dropForeignKey('slave_subscription_id')
            ->removeColumn('slave_subscription_id')
            ->update();
    }
}
