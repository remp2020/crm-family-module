<?php
declare(strict_types=1);

namespace Crm\FamilyModule\Events;

use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Exception;
use League\Event\AbstractListener;
use League\Event\EventInterface;

class FamilyRequestDeactivationSyncHandler extends AbstractListener
{
    public function __construct(
        private readonly FamilyRequestsRepository $familyRequestsRepository,
        private readonly DonateSubscription $donateSubscription,
    ) {
    }

    public function handle(EventInterface $event): void
    {
        if (!($event instanceof FamilyRequestCanceledEvent)) {
            throw new Exception('Unable to handle event, expected FamilyRequestCanceledEvent, received [' . get_class($event) . ']');
        }

        $familyRequest = $event->getFamilyRequest();
        $masterSubscription = $familyRequest->master_subscription;
        $nextSubscription = $this->donateSubscription->getNextFamilySubscription($masterSubscription);

        if ($nextSubscription) {
            $slaveUser = $familyRequest->slave_user;
            $subscriptionType = $familyRequest->subscription_type;

            // Find accepted request for same user and subscription type
            $nextSubscriptionRequest = $this->familyRequestsRepository
                ->masterSubscriptionAcceptedFamilyRequests($nextSubscription)
                ->where('slave_user_id', $slaveUser->id)
                ->where('subscription_type_id', $subscriptionType->id)
                ->fetch();

            if ($nextSubscriptionRequest) {
                // Release the family request on the next subscription
                // This will cancel it, stop the slave subscription, create a replacement,
                // and recursively sync to further subscriptions in the chain
                $this->donateSubscription->releaseFamilyRequest($nextSubscriptionRequest);
            }
        }
    }
}
