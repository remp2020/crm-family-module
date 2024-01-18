<?php

namespace Crm\FamilyModule\Events;

use Crm\ApplicationModule\NowTrait;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Events\SubscriptionShortenedEvent;
use Crm\SubscriptionsModule\Models\Subscription\StopSubscriptionHandler;
use League\Event\AbstractListener;
use League\Event\EventInterface;

class SubscriptionShortenedHandler extends AbstractListener
{
    use NowTrait;

    private $familyRequestsRepository;

    private $donateSubscription;

    private $familySubscriptionTypesRepository;

    private $stopSubscriptionHandler;

    public function __construct(
        FamilyRequestsRepository $familyRequestsRepository,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        DonateSubscription $donateSubscription,
        StopSubscriptionHandler $stopSubscriptionHandler
    ) {
        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->donateSubscription = $donateSubscription;
        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
        $this->stopSubscriptionHandler = $stopSubscriptionHandler;
    }

    public function handle(EventInterface $event)
    {
        if (!$event instanceof SubscriptionShortenedEvent) {
            throw new \Exception('Invalid type of event received, SubscriptionShortenedEvent expected: ' . get_class($event));
        }

        $subscription = $event->getBaseSubscription();

        if ($this->familySubscriptionTypesRepository->isSlaveSubscriptionType($subscription->subscription_type)) {
            $slaveFamilyRequest = $this->familyRequestsRepository->findSlaveSubscriptionFamilyRequest($subscription);
            $masterSubscription = $slaveFamilyRequest->master_subscription;
            if ($masterSubscription->end_time > $this->getNow()) {
                $this->donateSubscription->releaseFamilyRequest($slaveFamilyRequest);
            }
            return;
        }

        if ($this->familySubscriptionTypesRepository->isMasterSubscriptionType($subscription->subscription_type)) {
            $familyRequests = $this->familyRequestsRepository->masterSubscriptionFamilyRequests($subscription);
            foreach ($familyRequests as $familyRequest) {
                if ($familyRequest->status === FamilyRequestsRepository::STATUS_ACCEPTED) {
                    $this->stopSubscriptionHandler->stopSubscription($familyRequest->slave_subscription);
                }
            }
        }
    }
}
