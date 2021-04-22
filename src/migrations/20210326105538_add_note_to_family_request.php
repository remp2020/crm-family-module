<?php

use Phinx\Migration\AbstractMigration;

class AddNoteToFamilyRequest extends AbstractMigration
{
    public function change()
    {
        $this->table('family_requests')
            ->addColumn('note', 'string')
            ->update();
    }
}
