<?php

use Phinx\Migration\AbstractMigration;

class FamilyModuleInitMigration extends AbstractMigration
{
    public function up()
    {
        if (!$this->hasTable('family_requests')) {
            $this->table('family_requests')
                ->addColumn('master_user_id', 'integer', ['null' => false])
                ->addColumn('slave_user_id', 'integer', ['null' => true])
                ->addColumn('master_subscription_id', 'integer', ['null' => false])
                ->addColumn('status', 'string', ['null' => false])
                ->addColumn('code', 'string', ['null' => false])
                ->addColumn('subscription_type_id', 'integer', ['null' => false])
                ->addColumn('created_at', 'datetime', ['null' => false])
                ->addColumn('updated_at', 'datetime', ['null' => false])
                ->addColumn('opened_at', 'datetime', ['null' => true])
                ->addColumn('accepted_at', 'datetime', ['null' => true])
                ->addColumn('canceled_at', 'datetime', ['null' => true])
                ->addColumn('expires_at', 'datetime', ['null' => true])
                ->addForeignKey('master_subscription_id', 'subscriptions')
                ->addForeignKey('master_user_id', 'users')
                ->addForeignKey('slave_user_id', 'users')
                ->addForeignKey('subscription_type_id', 'subscription_types')
                ->addIndex('code', ['unique' => true])
                ->create();
        }

        if (!$this->hasTable('family_subscriptions')) {
            $this->table('family_subscriptions')
                ->addColumn('master_subscription_id', 'integer', ['null' => false])
                ->addColumn('slave_subscription_id', 'integer', ['null' => false])
                ->addColumn('type', 'string', ['null' => false])
                ->addColumn('created_at', 'datetime', ['null' => false])
                ->addForeignKey('master_subscription_id', 'subscriptions')
                ->addForeignKey('slave_subscription_id', 'subscriptions')
                ->create();
        }

        if (!$this->hasTable('family_subscription_types')) {
            $this->table('family_subscription_types')
                ->addColumn('master_subscription_type_id', 'integer', ['null' => false])
                ->addColumn('slave_subscription_type_id', 'integer', ['null' => false])
                ->addColumn('donation_method', 'string', ['null' => false])
                ->addColumn('count', 'integer', ['null' => false])
                ->addColumn('is_paid', 'boolean', ['null' => false])
                ->addForeignKey('master_subscription_type_id', 'subscription_types')
                ->addForeignKey('slave_subscription_type_id', 'subscription_types')
                ->addIndex('master_subscription_type_id', ['unique' => true])
                ->create();
        }
    }

    public function down()
    {
        $this->table('family_subscription_types')->drop()->update();
        $this->table('family_subscriptions')->drop()->update();
        $this->table('family_requests')->drop()->update();
    }
}
