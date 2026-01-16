<?php
declare(strict_types=1);

namespace Crm\FamilyModule\Events;

use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use DateTime;
use Exception;
use League\Event\AbstractListener;
use League\Event\EventInterface;
use Nette\Database\Table\ActiveRow;
use Tracy\Debugger;

class FamilyRequestActivationSyncHandler extends AbstractListener
{
    public function __construct(
        private readonly FamilyRequestsRepository $familyRequestsRepository,
        private readonly DonateSubscription $donateSubscription,
    ) {
    }

    public function handle(EventInterface $event): void
    {
        if (!($event instanceof FamilyRequestAcceptedEvent)) {
            throw new Exception('Unable to handle event, expected FamilyRequestAcceptedEvent, received [' . get_class($event) . ']');
        }

        $familyRequest = $event->getFamilyRequest();
        $masterSubscription = $familyRequest->master_subscription;
        $nextSubscription = $this->donateSubscription->getNextFamilySubscription($masterSubscription);

        if ($nextSubscription) {
            $slaveUser = $familyRequest->slave_user;
            $subscriptionType = $familyRequest->subscription_type;

            // Find unused request with same subscription type
            $nextSubscriptionRequest = $this->familyRequestsRepository
                ->masterSubscriptionUnusedFamilyRequests($nextSubscription)
                ->where('subscription_type_id', $subscriptionType->id)
                ->fetch();

            if ($nextSubscriptionRequest) {
                // Copy note from original request to next subscription's request
                if ($familyRequest->note) {
                    $this->familyRequestsRepository->update($nextSubscriptionRequest, [
                        'note' => $familyRequest->note,
                        'updated_at' => new DateTime(),
                    ]);
                    $nextSubscriptionRequest = $this->familyRequestsRepository->find($nextSubscriptionRequest->id);
                }

                // Activate the request by calling connectFamilyUser which will create the subscription
                // This will emit FamilyRequestAcceptedEvent again, but since the next subscription
                // won't have a next-next subscription yet, it won't recurse infinitely
                $result = $this->donateSubscription->connectFamilyUser($slaveUser, $nextSubscriptionRequest);
                if (!($result instanceof ActiveRow)) {
                    Debugger::log("Failed to sync activation to next subscription: subscription #{$nextSubscription->id}, user #{$slaveUser->id}, error: {$result}", Debugger::ERROR);
                }
            } else {
                Debugger::log("Not enough family requests when syncing activation to next subscription: subscription #{$nextSubscription->id}, user #{$slaveUser->id}", Debugger::WARNING);
            }
        }
    }
}
