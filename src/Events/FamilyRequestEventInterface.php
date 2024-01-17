<?php
declare(strict_types=1);

namespace Crm\FamilyModule\Events;

use Nette\Database\Table\ActiveRow;

interface FamilyRequestEventInterface
{
    public function getFamilyRequest(): ActiveRow;
}
