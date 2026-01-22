<?php
declare(strict_types=1);

namespace Crm\FamilyModule\Events;

use Crm\FamilyModule\Models\FamilyRequests;
use Exception;
use League\Event\AbstractListener;
use League\Event\EventInterface;

class CreateOnDemandRequestEventHandler extends AbstractListener
{
    public function __construct(
        private readonly FamilyRequests $familyRequests,
    ) {
    }

    public function handle(EventInterface $event): void
    {
        if (!($event instanceof FamilyRequestAcceptedEvent)) {
            throw new Exception('Unable to handle event, expected FamilyRequestAcceptedEvent, received [' . get_class($event) . ']');
        }

        $familyRequest = $event->getFamilyRequest();
        $masterSubscription = $familyRequest->master_subscription;
        $this->familyRequests->createFromSubscription($masterSubscription);
    }
}
