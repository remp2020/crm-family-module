<?php

namespace Crm\FamilyModule\Models;

use Crm\ApplicationModule\NowTrait;
use Crm\FamilyModule\FamilyModule;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesMetaRepository;
use Nette\Database\Table\IRow;
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

    private $familySubscriptionsRepository;

    private $subscriptionMetaRepository;

    private $familySubscriptionTypesRepository;

    public function __construct(
        SubscriptionsRepository $subscriptionsRepository,
        SubscriptionMetaRepository $subscriptionMetaRepository,
        SubscriptionTypesMetaRepository $subscriptionTypesMetaRepository,
        FamilyRequestsRepository $familyRequestsRepository,
        FamilySubscriptionsRepository $familySubscriptionsRepository,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository
    ) {
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->subscriptionTypesMetaRepository = $subscriptionTypesMetaRepository;
        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->familySubscriptionsRepository = $familySubscriptionsRepository;
        $this->subscriptionMetaRepository = $subscriptionMetaRepository;
        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
    }

    public function connectFamilyUser(IRow $slaveUser, IRow $familyRequest)
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

        $this->familyRequestsRepository->update($familyRequest, [
            'status' => FamilyRequestsRepository::STATUS_ACCEPTED,
            'slave_user_id' => $slaveUser->id,
            'accepted_at' => $this->getNow(),
            'updated_at' => $this->getNow(),
        ]);

        $slaveSubscription = null;
        if ($familySubscriptionType && $familySubscriptionType->donation_method === 'copy') {
            $slaveSubscription = $this->subscriptionsRepository->add(
                $familyRequest->subscription_type,
                false,
                $familySubscriptionType->is_paid,
                $slaveUser,
                FamilyModule::TYPE_FAMILY,
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
                FamilyModule::TYPE_FAMILY,
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

            $slaveSubscription = $this->subscriptionsRepository->add(
                $familyRequest->subscription_type,
                false,
                $masterSubscription->is_paid,
                $slaveUser,
                FamilyModule::TYPE_FAMILY,
                $this->getNow(),
                $endTime
            );
        }

        if (!$slaveSubscription) {
            return self::ERROR_INTERNAL;
        }

        $familySubscription = $this->familySubscriptionsRepository->add(
            $familyRequest,
            $slaveSubscription,
            FamilySubscriptionsRepository::TYPE_SINGLE
        );

        // If there is already some future family subscription, activate one of its (unused) requests as well
        $nextSubscription = $this->getNextFamilySubscription($masterSubscription);
        if ($nextSubscription) {
            $this->activateNextFamilySubscriptionRequest($nextSubscription, $slaveUser);
        }

        return $familySubscription;
    }

    public function stopFamilySubscription(IRow $familySubscription)
    {
        $this->subscriptionsRepository->update($familySubscription->slave_subscription, [
            'end_time' => $this->getNow(),
        ]);
        $this->familyRequestsRepository->update($familySubscription->family_request, [
            'status' => FamilyRequestsRepository::STATUS_CANCELED,
            'canceled_at' => $this->getNow(),
        ]);
    }

    private function activateNextFamilySubscriptionRequest(IRow $subscription, $user)
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

    private function getNextFamilySubscription(IRow $subscription)
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
