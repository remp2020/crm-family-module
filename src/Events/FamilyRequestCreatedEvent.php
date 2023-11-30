<?php
declare(strict_types=1);

namespace Crm\FamilyModule\Events;

use League\Event\AbstractEvent;
use Nette\Database\Table\ActiveRow;

class FamilyRequestCreatedEvent extends AbstractEvent
{
    public function __construct(
        private ActiveRow $familyRequest
    ) {
    }

    public function getFamilyRequest(): ActiveRow
    {
        return $this->familyRequest;
    }
}
