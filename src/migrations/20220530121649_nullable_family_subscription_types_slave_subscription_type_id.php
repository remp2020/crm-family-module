<?php

use Phinx\Migration\AbstractMigration;

class NullableFamilySubscriptionTypesSlaveSubscriptionTypeId extends AbstractMigration
{
    public function change()
    {
        $this->table('family_subscription_types')
            ->changeColumn('slave_subscription_type_id', 'integer', ['null' => true])
            ->update();
    }
}
