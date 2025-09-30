<?php

namespace Crm\FamilyModule\Events;

use Crm\ApplicationModule\Models\NowTrait;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\SubscriptionsModule\Events\SubscriptionShortenedEvent;
use Crm\SubscriptionsModule\Models\Subscription\StopSubscriptionHandler;
use League\Event\AbstractListener;
use League\Event\EventInterface;

class SubscriptionShortenedHandler extends AbstractListener
{
    use NowTrait;

    private $familyRequestsRepository;

    private $donateSubscription;

    private $stopSubscriptionHandler;

    public function __construct(
        FamilyRequestsRepository $familyRequestsRepository,
        DonateSubscription $donateSubscription,
        StopSubscriptionHandler $stopSubscriptionHandler,
    ) {
        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->donateSubscription = $donateSubscription;
        $this->stopSubscriptionHandler = $stopSubscriptionHandler;
    }

    public function handle(EventInterface $event)
    {
        if (!$event instanceof SubscriptionShortenedEvent) {
            throw new \Exception('Invalid type of event received, SubscriptionShortenedEvent expected: ' . get_class($event));
        }

        $subscription = $event->getBaseSubscription();

        $slaveFamilyRequest = $this->familyRequestsRepository->findSlaveSubscriptionFamilyRequest($subscription);
        if ($slaveFamilyRequest) {
            $masterSubscription = $slaveFamilyRequest->master_subscription;
            if ($masterSubscription->end_time > $this->getNow()) {
                $this->donateSubscription->releaseFamilyRequest($slaveFamilyRequest);
            }
            return;
        }

        $masterFamilyRequest = $this->familyRequestsRepository->masterSubscriptionFamilyRequests($subscription);
        if ($masterFamilyRequest->count('*') > 0) {
            foreach ($masterFamilyRequest as $familyRequest) {
                if ($familyRequest->status === FamilyRequestsRepository::STATUS_ACCEPTED) {
                    $this->stopSubscriptionHandler->stopSubscription($familyRequest->slave_subscription);
                }
            }
        }
    }
}
