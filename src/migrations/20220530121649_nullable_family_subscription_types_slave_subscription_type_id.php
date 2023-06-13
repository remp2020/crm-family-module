<?php

use Phinx\Migration\AbstractMigration;

class NullableFamilySubscriptionTypesSlaveSubscriptionTypeId extends AbstractMigration
{
    public function up()
    {
        $this->table('family_subscription_types')
            ->changeColumn('slave_subscription_type_id', 'integer', ['null' => true])
            ->update();
    }

    public function down()
    {
        $this->table('family_subscription_types')
            ->changeColumn('slave_subscription_type_id', 'integer', ['null' => false])
            ->update();
    }
}
