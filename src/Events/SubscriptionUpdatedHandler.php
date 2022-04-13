<?php

namespace Crm\FamilyModule\Events;

use Crm\ApplicationModule\NowTrait;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Events\SubscriptionUpdatedEvent;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use League\Event\AbstractListener;
use League\Event\EventInterface;

class SubscriptionUpdatedHandler extends AbstractListener
{
    use NowTrait;

    private $familySubscriptionTypesRepository;

    private $familyRequestsRepository;

    private $subscriptionsRepository;

    public function __construct(
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        FamilyRequestsRepository $familyRequestsRepository,
        SubscriptionsRepository $subscriptionsRepository
    ) {
        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
    }

    public function handle(EventInterface $event)
    {
        if (!$event instanceof SubscriptionUpdatedEvent) {
            throw new \Exception('Invalid type of event received, SubscriptionUpdatedEvent expected: ' . get_class($event));
        }

        $subscription = $event->getSubscription();
        if (!$this->familySubscriptionTypesRepository->isMasterSubscriptionType($subscription->subscription_type)) {
            return;
        }

        $familySubscriptionType = $this->familySubscriptionTypesRepository->findByMasterSubscriptionType($subscription->subscription_type);
        if ($familySubscriptionType->donation_method !== 'copy') {
            return;
        }

        $familyRequests = $this->familyRequestsRepository->masterSubscriptionFamilyRequests($subscription);
        foreach ($familyRequests as $familyRequest) {
            if (!$familyRequest->slave_subscription || $familyRequest->status === FamilyRequestsRepository::STATUS_CANCELED) {
                continue;
            }
            if ($subscription->start_time === $familyRequest->slave_subscription->start_time
                && $subscription->end_time === $familyRequest->slave_subscription->end_time
            ) {
                continue;
            }

            $this->subscriptionsRepository->update(
                $familyRequest->slave_subscription,
                [
                    'start_time' => $subscription->start_time,
                    'end_time' => $subscription->end_time,
                ]
            );
        }
    }
}
