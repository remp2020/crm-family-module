<?php
declare(strict_types=1);

namespace Crm\FamilyModule\Events;

use Crm\ApplicationModule\Models\Database\ActiveRow;
use League\Event\AbstractEvent;

class FamilyRequestAcceptedEvent extends AbstractEvent implements FamilyRequestEventInterface
{
    public function __construct(
        private ActiveRow $familyRequest,
    ) {
    }

    public function getFamilyRequest(): \Nette\Database\Table\ActiveRow
    {
        return $this->familyRequest;
    }
}
