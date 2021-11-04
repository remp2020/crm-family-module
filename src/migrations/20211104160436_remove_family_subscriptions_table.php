<?php

use Phinx\Migration\AbstractMigration;

class RemoveFamilySubscriptionsTable extends AbstractMigration
{
    public function up()
    {
        $this->table('family_subscriptions')
            ->drop()
            ->update();
    }

    public function down()
    {
        $this->table('family_subscriptions')
            ->addColumn('family_request_id', 'integer', ['null' => false])
            ->addColumn('master_subscription_id', 'integer', ['null' => false])
            ->addColumn('slave_subscription_id', 'integer', ['null' => false])
            ->addColumn('type', 'string', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addForeignKey('family_request_id', 'family_requests')
            ->addForeignKey('master_subscription_id', 'subscriptions')
            ->addForeignKey('slave_subscription_id', 'subscriptions')
            ->create();
    }
}