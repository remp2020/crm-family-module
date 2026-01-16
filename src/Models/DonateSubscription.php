<?php

namespace Crm\FamilyModule\Models;

use Crm\ApplicationModule\Models\NowTrait;
use Crm\FamilyModule\Events\FamilyRequestAcceptedEvent;
use Crm\FamilyModule\Events\FamilyRequestCanceledEvent;
use Crm\FamilyModule\FamilyModule;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Models\Subscription\StopSubscriptionHandler;
use Crm\SubscriptionsModule\Repositories\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use Exception;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;
use Nette\Security\User;
use Nette\Utils\DateTime;

class DonateSubscription
{
    use NowTrait;

    public const ERROR_INTERNAL = 'error-interal';
    public const ERROR_IN_USE = 'error-in-use'; // TODO: remp/crm/#1360 rename this constant; name isn't self explanatory (eg. ERROR_ONE_PER_USER)
    public const ERROR_SELF_USE = 'error-self-use';
    public const ERROR_MASTER_SUBSCRIPTION_EXPIRED = 'master-subscription-expired';
    public const ERROR_REQUEST_WRONG_STATUS = 'error-request-wrong-status';

    public const IS_PAID_SUBSCRIPTION_TYPE_META_KEY = 'family_subscription_type_is_paid';

    public function __construct(
        private readonly SubscriptionsRepository $subscriptionsRepository,
        private readonly SubscriptionMetaRepository $subscriptionMetaRepository,
        private readonly SubscriptionTypesMetaRepository $subscriptionTypesMetaRepository,
        private readonly FamilyRequestsRepository $familyRequestsRepository,
        private readonly FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        private readonly StopSubscriptionHandler $stopSubscriptionHandler,
        private readonly Emitter $emitter,
        private readonly User $user,
    ) {
    }

    public function connectFamilyUser(ActiveRow $slaveUser, ActiveRow $familyRequest)
    {
        $masterSubscription = $familyRequest->master_subscription;

        $familySubscriptionType = $this->familySubscriptionTypesRepository
            ->findByMasterSubscriptionType($masterSubscription->subscription_type);

        $subscriptionMeta = $this->subscriptionMetaRepository->subscriptionMeta($masterSubscription);
        $isAdmin = $this->user->getIdentity()?->role === UsersRepository::ROLE_ADMIN;

        if (!$isAdmin) {
            if ($familySubscriptionType && $familySubscriptionType->donation_method === 'copy') {
                if ($this->familyRequestsRepository->userAlreadyHasSubscriptionFromMasterWithSubscriptionType(
                    $masterSubscription,
                    $slaveUser,
                    $familyRequest->subscription_type,
                )) {
                    return self::ERROR_IN_USE;
                }
            }

            $hasTimeLimitedFamilyType = isset($subscriptionMeta['family_subscription_type'])
                && in_array($subscriptionMeta['family_subscription_type'], ['days', 'fixed'], true);

            if ($hasTimeLimitedFamilyType && $masterSubscription->user_id === $slaveUser->id) {
                return self::ERROR_SELF_USE;
            }
        }

        $masterSubscriptionExpired = $masterSubscription->end_time <= $this->getNow();
        $familyRequestExpired = $familyRequest->expires_at && $familyRequest->expires_at <= $this->getNow();

        if ($masterSubscriptionExpired || $familyRequestExpired) {
            return self::ERROR_MASTER_SUBSCRIPTION_EXPIRED;
        }

        if ($familyRequest->status !== FamilyRequestsRepository::STATUS_CREATED) {
            return self::ERROR_REQUEST_WRONG_STATUS;
        }

        $donationMethod = null;
        $isPaid = false;
        if ($familySubscriptionType) {
            $donationMethod = $familySubscriptionType->donation_method;
            $isPaid = $familySubscriptionType->is_paid;
        } elseif (isset($subscriptionMeta['family_subscription_type'])) {
            $donationMethod = $subscriptionMeta['family_subscription_type'];
            $isPaid = $masterSubscription->is_paid;
        }

        $subscriptionTypeIsPaidMetaValue = $this->subscriptionTypesMetaRepository->getMetaValue(
            $familyRequest->subscription_type,
            self::IS_PAID_SUBSCRIPTION_TYPE_META_KEY,
        );
        if ($subscriptionTypeIsPaidMetaValue !== null) {
            $isPaid = (bool) $subscriptionTypeIsPaidMetaValue;
        }

        $slaveSubscription = null;
        if ($donationMethod === 'copy') {
            $slaveSubscription = $this->subscriptionsRepository->add(
                $familyRequest->subscription_type,
                false,
                $isPaid,
                $slaveUser,
                FamilyModule::SUBSCRIPTION_TYPE_FAMILY,
                $masterSubscription->start_time,
                $masterSubscription->end_time,
            );
        } elseif (isset($subscriptionMeta['family_subscription_type']) && $subscriptionMeta['family_subscription_type'] === 'days') {
            if (!isset($subscriptionMeta['family_subscription_days'])) {
                throw new Exception("Missing required subscription meta 'family_subscription_days' for 'family_subscription_type' = 'days',  subscription #{$masterSubscription->id}");
            }

            $subscriptionExtension = $this->subscriptionsRepository
                ->getSubscriptionExtension($familyRequest->subscription_type, $slaveUser);
            $startTime = $subscriptionExtension->getDate();
            $endTime = (clone $startTime)->modify(sprintf('+%d days', $subscriptionMeta['family_subscription_days']));
            $slaveSubscription = $this->subscriptionsRepository->add(
                $familyRequest->subscription_type,
                false,
                $masterSubscription->is_paid,
                $slaveUser,
                FamilyModule::SUBSCRIPTION_TYPE_FAMILY,
                $startTime,
                $endTime,
            );
        } elseif (isset($subscriptionMeta['family_subscription_type']) && $subscriptionMeta['family_subscription_type'] === 'fixed') {
            $endTime = null;
            if (isset($subscriptionMeta['family_subscription_fixed_expiration'])) {
                $endTime = DateTime::from($subscriptionMeta['family_subscription_fixed_expiration']);
            } elseif (isset($familyRequest->subscription_type->fixed_end)) {
                $endTime = DateTime::from($familyRequest->subscription_type->fixed_end);
            }

            if (!isset($endTime)) {
                throw new Exception("Missing subscription end time set either with subscription meta key 'family_subscription_fixed_expiration' or in 'fixed_end' column of gifted subscription type.");
            }

            $isPaid = $this->subscriptionTypesMetaRepository
                ->getMetaValue($familyRequest->subscription_type, 'is_paid');
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
                $endTime,
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

        $familyRequest = $this->familyRequestsRepository->find($familyRequest->id);
        $this->emitter->emit(new FamilyRequestAcceptedEvent($familyRequest));

        return $familyRequest;
    }

    public function releaseFamilyRequest(
        ActiveRow $familyRequest,
        bool $isAdmin = false,
    ): void {
        // do not cancel already cancelled family request (e.g. second call on handler's URL)
        // otherwise multiple "substitute" child subscriptions will be generated
        if ($familyRequest->status !== FamilyRequestsRepository::STATUS_ACCEPTED) {
            return;
        }

        // first expire family request to avoid recursion caused by stopped subscription event handling
        $this->familyRequestsRepository->update($familyRequest, [
            'status' => FamilyRequestsRepository::STATUS_CANCELED,
            'canceled_at' => $this->getNow(),
        ]);

        $slaveSubscription = $familyRequest->slave_subscription;
        // only stop if subscription hasn't ended yet
        if ($slaveSubscription->end_time >= $this->getNow()) {
            $this->stopSubscriptionHandler->stopSubscription($slaveSubscription, $isAdmin);
        }

        // Create replacement request to maintain the total count of available family slots
        $this->familyRequestsRepository->add(
            $familyRequest->master_subscription,
            $familyRequest->subscription_type,
        );

        // Sync cancellation to next subscription in renewal chain
        $familyRequest = $this->familyRequestsRepository->find($familyRequest->id);
        $this->emitter->emit(new FamilyRequestCanceledEvent($familyRequest));
    }

    public function syncNoteToNextSubscription(ActiveRow $familyRequest): void
    {
        $nextSubscription = $this->getNextFamilySubscription($familyRequest->master_subscription);
        if (!$nextSubscription) {
            return;
        }

        $isAccepted = $familyRequest->status === FamilyRequestsRepository::STATUS_ACCEPTED;

        $query = $isAccepted
            ? $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($nextSubscription)
                ->where('slave_user_id', $familyRequest->slave_user_id)
            : $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($nextSubscription);

        $nextRequest = $query
            ->where('subscription_type_id', $familyRequest->subscription_type_id)
            ->fetch();

        if ($nextRequest) {
            $this->familyRequestsRepository->update($nextRequest, [
                'note' => $familyRequest->note,
                'updated_at' => $this->getNow(),
            ]);

            // Recursively sync to further subscriptions
            $this->syncNoteToNextSubscription($this->familyRequestsRepository->find($nextRequest->id));
        }
    }

    public function getNextFamilySubscription(ActiveRow $subscription): ?ActiveRow
    {
        if (!$subscription->next_subscription_id) {
            return null;
        }
        return $this->subscriptionsRepository->find($subscription->next_subscription_id);
    }
}
