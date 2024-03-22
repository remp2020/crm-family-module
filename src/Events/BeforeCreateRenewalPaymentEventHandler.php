<?php

namespace Crm\FamilyModule\Events;

use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\PaymentsModule\Events\BeforeCreateRenewalPaymentEvent;
use League\Event\AbstractListener;
use League\Event\EventInterface;
use Tracy\Debugger;
use Tracy\ILogger;

class BeforeCreateRenewalPaymentEventHandler extends AbstractListener
{
    public function __construct(private FamilySubscriptionTypesRepository $familySubscriptionTypesRepository)
    {
    }

    public function handle(EventInterface $event)
    {
        if (!($event instanceof BeforeCreateRenewalPaymentEvent)) {
            throw new \Exception('Unable to handle event, expected CreatingNewPaymentEvent, received [' . get_class($event) . ']');
        }

        $oldSubscriptionType = $event->getEndingSubscriptionType();

        // prevent creating payment if subscription type is family remp/novydenik#1147
        $isFamilySubscriptionType = $this->familySubscriptionTypesRepository->isFamilySubscriptionType($oldSubscriptionType);
        if ($isFamilySubscriptionType) {
            Debugger::log("Scenario trying to create new payment from family subscription type", ILogger::WARNING);
            $event->preventCreatingRenewalPayment();
        }
    }
}
