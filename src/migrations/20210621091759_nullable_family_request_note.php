<?php

use Phinx\Migration\AbstractMigration;

class NullableFamilyRequestNote extends AbstractMigration
{
    public function up()
    {
        $this->table('family_requests')
            ->changeColumn('note', 'string', ['null' => true])
            ->update();
    }

    public function down()
    {
        $this->table('family_requests')
            ->changeColumn('note', 'string', ['null' => false])
            ->update();
    }
}
