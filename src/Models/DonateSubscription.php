<?php

namespace Crm\FamilyModule\Models;

use Crm\ApplicationModule\NowTrait;
use Crm\FamilyModule\FamilyModule;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesMetaRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Tracy\Debugger;

class DonateSubscription
{
    use NowTrait;

    public const ERROR_INTERNAL = 'error-interal';
    public const ERROR_IN_USE = 'error-in-use'; // TODO: remp/crm/#1360 rename this constant; name isn't self explanatory (eg. ERROR_ONE_PER_USER)
    public const ERROR_SELF_USE = 'error-self-use';
    public const ERROR_MASTER_SUBSCRIPTION_EXPIRED = 'master-subscription-expired';

    private $subscriptionsRepository;

    private $subscriptionTypesMetaRepository;

    private $familyRequestsRepository;

    private $subscriptionMetaRepository;

    private $familySubscriptionTypesRepository;

    public function __construct(
        SubscriptionsRepository $subscriptionsRepository,
        SubscriptionMetaRepository $subscriptionMetaRepository,
        SubscriptionTypesMetaRepository $subscriptionTypesMetaRepository,
        FamilyRequestsRepository $familyRequestsRepository,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository
    ) {
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->subscriptionTypesMetaRepository = $subscriptionTypesMetaRepository;
        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->subscriptionMetaRepository = $subscriptionMetaRepository;
        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
    }

    public function connectFamilyUser(ActiveRow $slaveUser, ActiveRow $familyRequest)
    {
        $masterSubscription = $familyRequest->master_subscription;

        $familySubscriptionType = $this->familySubscriptionTypesRepository->findByMasterSubscriptionType($masterSubscription->subscription_type);

        $subscriptionMeta = $this->subscriptionMetaRepository->subscriptionMeta($masterSubscription);

        if ($familySubscriptionType && $familySubscriptionType->donation_method === 'copy') {
            if ($this->familyRequestsRepository->userAlreadyHasSubscriptionFrom($masterSubscription, $slaveUser)) {
                return self::ERROR_IN_USE;
            }
        }
        if (isset($subscriptionMeta['family_subscription_type']) && in_array($subscriptionMeta['family_subscription_type'], ['days', 'fixed'])) {
            if ($masterSubscription->user_id === $slaveUser->id) {
                return self::ERROR_SELF_USE;
            }
        }

        if ($masterSubscription->end_time <= $this->getNow() || ($familyRequest->expires_at && $familyRequest->expires_at <= $this->getNow())) {
            return self::ERROR_MASTER_SUBSCRIPTION_EXPIRED;
        }

        $slaveSubscription = null;
        if ($familySubscriptionType && $familySubscriptionType->donation_method === 'copy') {
            $slaveSubscription = $this->subscriptionsRepository->add(
                $familyRequest->subscription_type,
                false,
                $familySubscriptionType->is_paid,
                $slaveUser,
                FamilyModule::SUBSCRIPTION_TYPE_FAMILY,
                $masterSubscription->start_time,
                $masterSubscription->end_time
            );
        } elseif (isset($subscriptionMeta['family_subscription_type']) && $subscriptionMeta['family_subscription_type'] === 'days') {
            if (!isset($subscriptionMeta['family_subscription_days'])) {
                throw new \Exception("Missing required subscription meta 'family_subscription_days' for 'family_subscription_type' = 'days',  subscription #{$masterSubscription->id}");
            }

            $subscriptionExtension = $this->subscriptionsRepository->getSubscriptionExtension($familyRequest->subscription_type, $slaveUser);
            $startTime = $subscriptionExtension->getDate();
            $endTime = $startTime->modifyClone(sprintf('+%d days', $subscriptionMeta['family_subscription_days']));
            $slaveSubscription = $this->subscriptionsRepository->add(
                $familyRequest->subscription_type,
                false,
                $masterSubscription->is_paid,
                $slaveUser,
                FamilyModule::SUBSCRIPTION_TYPE_FAMILY,
                $startTime,
                $endTime
            );
        } elseif (isset($subscriptionMeta['family_subscription_type']) && $subscriptionMeta['family_subscription_type'] === 'fixed') {
            $endTime = null;
            if (isset($subscriptionMeta['family_subscription_fixed_expiration'])) {
                $endTime = DateTime::from($subscriptionMeta['family_subscription_fixed_expiration']);
            } elseif (isset($familyRequest->subscription_type->fixed_end)) {
                $endTime = DateTime::from($familyRequest->subscription_type->fixed_end);
            }

            if (!isset($endTime)) {
                throw new \Exception("Missing subscription end time set either with subscription meta key 'family_subscription_fixed_expiration' or in 'fixed_end' column of gifted subscription type.");
            }

            $isPaid = $this->subscriptionTypesMetaRepository->getMetaValue($familyRequest->subscription_type, 'is_paid');
            if ($isPaid === null) {
                $isPaid = $masterSubscription->is_paid;
            }

            $slaveSubscription = $this->subscriptionsRepository->add(
                $familyRequest->subscription_type,
                false,
                $isPaid,
                $slaveUser,
                FamilyModule::SUBSCRIPTION_TYPE_FAMILY,
                $this->getNow(),
                $endTime
            );
        }

        if (!$slaveSubscription) {
            return self::ERROR_INTERNAL;
        }

        $this->familyRequestsRepository->update($familyRequest, [
            'status' => FamilyRequestsRepository::STATUS_ACCEPTED,
            'slave_subscription_id' => $slaveSubscription->id,
            'slave_user_id' => $slaveUser->id,
            'accepted_at' => $this->getNow(),
            'updated_at' => $this->getNow(),
        ]);

        // If there is already some future family subscription, activate one of its (unused) requests as well
        $nextSubscription = $this->getNextFamilySubscription($masterSubscription);
        if ($nextSubscription) {
            $this->activateNextFamilySubscriptionRequest($nextSubscription, $slaveUser);
        }

        return $familyRequest;
    }

    public function releaseFamilyRequest(ActiveRow $familyRequest)
    {
        // do not cancel already cancelled family request (eg. second call on handler's URL)
        // otherwise multiple "substitute" child subscriptions will be generated
        if ($familyRequest->status !== FamilyRequestsRepository::STATUS_ACCEPTED) {
            return;
        }

        $slaveSubscription = $familyRequest->slave_subscription;
        // already stopped subscription
        if ($slaveSubscription->end_time >= $this->getNow()) {
            $this->subscriptionsRepository->update($slaveSubscription, [
                'end_time' => $this->getNow(),
            ]);
        }
        $this->familyRequestsRepository->update($familyRequest, [
            'status' => FamilyRequestsRepository::STATUS_CANCELED,
            'canceled_at' => $this->getNow(),
        ]);

        $this->familyRequestsRepository->add(
            $familyRequest->master_subscription,
            $familyRequest->subscription_type
        );
    }

    private function activateNextFamilySubscriptionRequest(ActiveRow $subscription, $user)
    {
        // Future family request shouldn't be shared yet
        // Grab one of the free requests
        $nextSubscriptionRequest = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($subscription)->fetch();
        if ($nextSubscriptionRequest) {
            $this->connectFamilyUser($user, $nextSubscriptionRequest);
        } else {
            Debugger::log("Not enough family requests when activating consecutive family subscription: subscription #{$subscription->id}, user #{$user->id}", Debugger::WARNING);
        }
    }

    private function getNextFamilySubscription(ActiveRow $subscription)
    {
        $nextSubscription = $this->subscriptionMetaRepository
            ->getMeta($subscription, FamilyRequests::NEXT_FAMILY_SUBSCRIPTION_META)
            ->fetch();
        if ($nextSubscription) {
            return $this->subscriptionsRepository->find($nextSubscription->value);
        }
        return null;
    }
}
