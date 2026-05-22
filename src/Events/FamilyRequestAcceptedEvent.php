<?php
declare(strict_types=1);

namespace Crm\FamilyModule\Events;

use Crm\UsersModule\Events\UserEventInterface;
use League\Event\AbstractEvent;
use Nette\Database\Table\ActiveRow;

class FamilyRequestAcceptedEvent extends AbstractEvent implements FamilyRequestEventInterface, UserEventInterface
{
    public function __construct(
        private readonly ActiveRow $familyRequest,
    ) {
    }

    public function getFamilyRequest(): ActiveRow
    {
        return $this->familyRequest;
    }

    public function getUser(): ActiveRow
    {
        return $this->familyRequest->slave_user;
    }
}
