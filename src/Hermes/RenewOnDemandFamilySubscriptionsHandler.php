<?php
declare(strict_types=1);

namespace Crm\FamilyModule\Hermes;

use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Models\FamilyChildSubscriptionRenewalException;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use DateTime;
use Tomaj\Hermes\Handler\HandlerInterface;
use Tomaj\Hermes\Handler\RetryTrait;
use Tomaj\Hermes\MessageInterface;
use Tracy\Debugger;

class RenewOnDemandFamilySubscriptionsHandler implements HandlerInterface
{
    use RetryTrait;

    public function __construct(
        private SubscriptionsRepository $subscriptionsRepository,
        private SubscriptionMetaRepository $subscriptionMetaRepository,
        private FamilyRequestsRepository $familyRequestsRepository,
        private DonateSubscription $donateSubscription,
    ) {
    }

    public function handle(MessageInterface $message): bool
    {
        $payload = $message->getPayload();

        $newSubscription = $this->subscriptionsRepository->find($payload['new_subscription_id']);
        $previousSubscription = $this->subscriptionsRepository->find($payload['previous_subscription_id']);

        if (!$newSubscription || !$previousSubscription) {
            Debugger::log(
                "RenewOnDemandFamilySubscriptionsHandler: subscription not found, new [{$payload['new_subscription_id']}], previous [{$payload['previous_subscription_id']}]",
                Debugger::ERROR,
            );
            return false;
        }

        $previousAcceptedRequests = $this->familyRequestsRepository
            ->masterSubscriptionAcceptedFamilyRequests($previousSubscription);

        if ($previousAcceptedRequests->count('*') === 0) {
            return true;
        }

        foreach ($previousAcceptedRequests as $previousRequest) {
            $request = $this->familyRequestsRepository
                ->masterSubscriptionFirstUnusedFamilyRequestBySubscriptionType($newSubscription, $previousRequest->subscription_type);

            if (!$request) {
                Debugger::log(
                    "RenewOnDemandFamilySubscriptionsHandler: no available family request found during on-demand renewal (there should be one), new subscription #{$newSubscription->id}, previous subscription #{$previousSubscription->id}",
                    Debugger::WARNING,
                );
                return false;
            }

            if ($previousRequest->note) {
                $this->familyRequestsRepository->update($request, [
                    'note' => $previousRequest->note,
                    'updated_at' => new DateTime(),
                ]);
                $request = $this->familyRequestsRepository->find($request->id);
            }

            $result = $this->donateSubscription->connectFamilyUser(
                $previousRequest->slave_subscription->user,
                $request,
            );

            if ($result === DonateSubscription::ERROR_INTERNAL) {
                throw new FamilyChildSubscriptionRenewalException(
                    "Unable to renew subscription for user #{$previousRequest->slave_subscription->user->id}, parent subscription #{$newSubscription->id}, request #{$request->id}",
                );
            }
            if ($result === DonateSubscription::ERROR_IN_USE) {
                Debugger::log(
                    "Duplicated donation for user #{$previousRequest->slave_subscription->user->id}, parent subscription #{$newSubscription->id}, request #{$request->id}",
                    Debugger::WARNING,
                );
                continue;
            }
            if ($result === DonateSubscription::ERROR_MASTER_SUBSCRIPTION_EXPIRED) {
                Debugger::log(
                    "Master subscription already expired #{$previousSubscription->id}, request #{$request->id}",
                    Debugger::ERROR,
                );

                // we can't recover from this, no point of throwing an exception and retry later
                return false;
            }

            if ($previousRequest->slave_subscription->address_id !== null
                && $result->slave_subscription->address_id === null
            ) {
                $this->subscriptionsRepository->update($result->slave_subscription, [
                    'address_id' => $previousRequest->slave_subscription->address_id,
                ]);
            }
        }

        $this->subscriptionMetaRepository->setMeta(
            subscription: $previousSubscription,
            key: FamilyRequests::NEXT_FAMILY_SUBSCRIPTION_META,
            value: $newSubscription->id,
        );

        return true;
    }
}
