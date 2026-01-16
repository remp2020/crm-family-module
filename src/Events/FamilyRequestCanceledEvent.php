<?php
declare(strict_types=1);

namespace Crm\FamilyModule\Events;

use League\Event\AbstractEvent;
use Nette\Database\Table\ActiveRow;

class FamilyRequestCanceledEvent extends AbstractEvent implements FamilyRequestEventInterface
{
    public function __construct(
        private readonly ActiveRow $familyRequest,
    ) {
    }

    public function getFamilyRequest(): ActiveRow
    {
        return $this->familyRequest;
    }
}
