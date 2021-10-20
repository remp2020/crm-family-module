<?php

namespace Crm\FamilyModule\Events;

use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Models\FamilyChildSubscriptionRenewalException;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Models\MissingFamilySubscriptionTypeException;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Events\NewSubscriptionEvent;
use Crm\SubscriptionsModule\Repository\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use League\Event\AbstractListener;
use League\Event\EventInterface;
use Nette\Database\Table\ActiveRow;
use Tracy\Debugger;

class NewSubscriptionHandler extends AbstractListener
{
    private $familyRequests;

    private $paymentsRepository;

    private $recurrentPaymentsRepository;

    private $donateSubscription;

    private $subscriptionsRepository;

    private $subscriptionMetaRepository;

    private $familySubscriptionTypesRepository;

    private $familyRequestsRepository;

    private $subscriptionsTimeGap;

    public function __construct(
        SubscriptionsRepository $subscriptionsRepository,
        SubscriptionMetaRepository $subscriptionMetaRepository,
        FamilyRequests $familyRequests,
        PaymentsRepository $paymentsRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        DonateSubscription $donateSubscription,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        FamilyRequestsRepository $familyRequestsRepository
    ) {
        $this->familyRequests = $familyRequests;
        $this->paymentsRepository = $paymentsRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->donateSubscription = $donateSubscription;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->subscriptionMetaRepository = $subscriptionMetaRepository;
        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
        $this->familyRequestsRepository = $familyRequestsRepository;
    }

    public function setSubscriptionsTimeGap(string $gap): void
    {
        $this->subscriptionsTimeGap = $gap;
    }

    public function handle(EventInterface $event)
    {
        if (!($event instanceof NewSubscriptionEvent)) {
            throw new \Exception('Unable to handle event, expected NewSubscriptionEvent, received [' . get_class($event) . ']');
        }

        $subscription = $event->getSubscription();

        try {
            $requests = $this->familyRequests->createFromSubscription($subscription);

            // Check if this has previous family subscription
            $previousFamilySubscription = $this->getPreviousFamilyPaymentSubscription($subscription);
            if ($previousFamilySubscription && $this->hasEnoughRequests($subscription, $previousFamilySubscription)) {
                $this->linkNextFamilySubscription($subscription, $previousFamilySubscription);
                $this->activateChildSubscriptions($subscription, $previousFamilySubscription, $requests);
            }
        } catch (MissingFamilySubscriptionTypeException $exception) {
            // everything all right, we don't want to create family requests if meta is missing
            return;
        } catch (\Exception $exception) {
            Debugger::log($exception, Debugger::EXCEPTION);
            return;
        }
    }

    private function getPreviousFamilyPaymentSubscription(ActiveRow $subscription)
    {
        // First, try to link subscriptions using recurrent payments
        $payment = $this->paymentsRepository->subscriptionPayment($subscription);
        if ($payment) {
            $recurrentPayment = $this->recurrentPaymentsRepository->findBy('payment_id', $payment->id);
            if ($recurrentPayment && $recurrentPayment->parent_payment_id && $recurrentPayment->parent_payment->subscription_id) {
                return $recurrentPayment->parent_payment->subscription;
            }
        }

        // Second, try to find previous family subscription with time gap (if set by setSubscriptionsTimeGap)
        if ($this->subscriptionsTimeGap !== null) {
            $dateGapStart = (new \DateTime($subscription->start_time))->modify('-' . $this->subscriptionsTimeGap);
        } else {
            $dateGapStart = $subscription->start_time;
        }

        $previousFamilySubscription = $this->subscriptionsRepository->getTable()
            ->where([
                'id != ?' => $subscription->id,
                'user_id' => $subscription->user_id,
                'end_time BETWEEN ? AND ?' => [$dateGapStart, $subscription->start_time],
                'subscription_type_id IN (?)' => array_values($this->familySubscriptionTypesRepository->masterSubscriptionTypes()),
            ]);

        if ($previousFamilySubscription->count('*') > 1) {
            return null;
        }

        return $previousFamilySubscription->fetch();
    }


    /**
     * @param ActiveRow $newSubscription
     * @param ActiveRow $previousSubscription
     * @param array $familyRequests
     *
     * @return array
     * @throws FamilyChildSubscriptionRenewalException
     */
    private function activateChildSubscriptions(ActiveRow $newSubscription, ActiveRow $previousSubscription, array $familyRequests): array
    {
        $previousFamilyRequests = $this->familyRequestsRepository->masterSubscriptionFamilyRequests($previousSubscription)->where('status', FamilyRequestsRepository::STATUS_ACCEPTED);

        $donatedSubscriptions = [];

        foreach ($previousFamilyRequests as $previousFamilyRequest) {
            $request = array_pop($familyRequests);
            // There should be enough requests generated, but if some are missing, report warning
            if (!$request) {
                Debugger::log("Not enough family requests when activating child subscriptions: subscription #{$newSubscription->id}, previous subscription {$previousSubscription->id}, request #{$request->id} generated", Debugger::WARNING);
                return $donatedSubscriptions;
            }

            $donatedSubscription = $this->donateSubscription->connectFamilyUser($previousFamilyRequest->slave_subscription->user, $request);
            if ($donatedSubscription === DonateSubscription::ERROR_INTERNAL) {
                throw new FamilyChildSubscriptionRenewalException("Unable to renew subscription for user #{$previousFamilyRequest->slave_subscription->user->id}, parent subscription #{$newSubscription->id}, request #{$request->id}");
            }

            if ($donatedSubscription === DonateSubscription::ERROR_IN_USE) {
                // this should not happen (duplicate donations are OK in this case)
                throw new FamilyChildSubscriptionRenewalException("Duplicated donation for user #{$previousFamilyRequest->slave_subscription->user->id}, parent subscription #{$newSubscription->id}, request #{$request->id}");
            }

            if ($donatedSubscription === DonateSubscription::ERROR_MASTER_SUBSCRIPTION_EXPIRED) {
                throw new FamilyChildSubscriptionRenewalException("Master subscription already expired #{$previousSubscription->id}}, request #{$request->id}");
            }

            $donatedSubscriptions[] = $donatedSubscription;
        }

        return $donatedSubscriptions;
    }

    private function linkNextFamilySubscription(ActiveRow $subscription, $previousFamilySubscription): void
    {
        $this->subscriptionMetaRepository->add($previousFamilySubscription, FamilyRequests::NEXT_FAMILY_SUBSCRIPTION_META, $subscription->id);
    }

    private function hasEnoughRequests($newSubscription, $previousSubscription): bool
    {
        $newRequestsCount = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($newSubscription)
            ->count('*');

        $previousRequestsCount = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($previousSubscription)
            ->count('*');

        return $newRequestsCount >= $previousRequestsCount;
    }
}
