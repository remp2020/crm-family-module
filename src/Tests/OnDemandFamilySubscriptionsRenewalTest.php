<?php

namespace Crm\FamilyModule\Tests;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
use Crm\ApplicationModule\Models\NowTrait;
use Crm\FamilyModule\Events\CreateOnDemandRequestEventHandler;
use Crm\FamilyModule\Events\FamilyRequestAcceptedEvent;
use Crm\FamilyModule\Events\FamilyRequestActivationSyncHandler;
use Crm\FamilyModule\Events\FamilyRequestCanceledEvent;
use Crm\FamilyModule\Events\FamilyRequestDeactivationSyncHandler;
use Crm\FamilyModule\Hermes\RenewOnDemandFamilySubscriptionsHandler;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\PaymentsModule\Repositories\PaymentMethodsRepository;
use Crm\SubscriptionsModule\Models\Generator\SubscriptionsGenerator;
use Crm\SubscriptionsModule\Models\Generator\SubscriptionsParams;
use Crm\SubscriptionsModule\Repositories\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Repositories\UsersRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class OnDemandFamilySubscriptionsRenewalTest extends BaseTestCase
{
    use NowTrait;

    private UserManager $userManager;
    private UsersRepository $usersRepository;
    private SubscriptionsGenerator $subscriptionsGenerator;
    private FamilyRequestsRepository $familyRequestsRepository;
    private LazyEventEmitter $lazyEventEmitter;
    private FamilyRequests $familyRequests;
    private DonateSubscription $donateSubscription;
    private SubscriptionsRepository $subscriptionsRepository;
    private SubscriptionMetaRepository $subscriptionMetaRepository;
    private RenewOnDemandFamilySubscriptionsHandler $renewHandler;

    protected function requiredRepositories(): array
    {
        return [
            ...parent::requiredRepositories(),
            PaymentMethodsRepository::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setNow(new DateTime());

        $this->lazyEventEmitter = $this->inject(LazyEventEmitter::class);
        $this->userManager = $this->inject(UserManager::class);
        $this->familyRequests = $this->inject(FamilyRequests::class);
        $this->donateSubscription = $this->inject(DonateSubscription::class);
        $this->renewHandler = $this->inject(RenewOnDemandFamilySubscriptionsHandler::class);

        $this->usersRepository = $this->getRepository(UsersRepository::class);
        $this->subscriptionsRepository = $this->getRepository(SubscriptionsRepository::class);
        $this->familyRequestsRepository = $this->getRepository(FamilyRequestsRepository::class);
        $this->subscriptionMetaRepository = $this->getRepository(SubscriptionMetaRepository::class);
        $this->subscriptionsGenerator = $this->inject(SubscriptionsGenerator::class);

        // Generate on-demand requests as each one gets accepted
        $this->lazyEventEmitter->addListener(FamilyRequestAcceptedEvent::class, $this->inject(CreateOnDemandRequestEventHandler::class));
        $this->lazyEventEmitter->addListener(FamilyRequestAcceptedEvent::class, $this->inject(FamilyRequestActivationSyncHandler::class));
        $this->lazyEventEmitter->addListener(FamilyRequestCanceledEvent::class, $this->inject(FamilyRequestDeactivationSyncHandler::class));
    }

    protected function tearDown(): void
    {
        $this->lazyEventEmitter->removeAllListeners(FamilyRequestAcceptedEvent::class);
        $this->lazyEventEmitter->removeAllListeners(FamilyRequestCanceledEvent::class);
        parent::tearDown();
    }

    public function testRenewsAllPreviouslyAcceptedRequests(): void
    {
        [$masterSubscriptionType] = $this->seedFamilySubscriptionTypes();

        $masterUser = $this->getUser('master@example.com');
        $slaveUser1 = $this->getUser('slave1@example.com');
        $slaveUser2 = $this->getUser('slave2@example.com');
        $slaveUser3 = $this->getUser('slave3@example.com');

        $previousSubscription = $this->createOnDemandSubscription($masterUser, $masterSubscriptionType);

        $this->activateOnDemandRequest($previousSubscription, $slaveUser1);
        $this->activateOnDemandRequest($previousSubscription, $slaveUser2);
        $this->activateOnDemandRequest($previousSubscription, $slaveUser3);

        $this->assertEquals(3, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($previousSubscription)->count());

        $newSubscription = $this->createOnDemandSubscription(
            $masterUser,
            $masterSubscriptionType,
            $this->getNow()->modify('+31 days'),
            $this->getNow()->modify('+62 days'),
        );
        $this->assertEquals(1, $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($newSubscription)->count());

        $result = $this->renewHandler->handle(new HermesMessage('renew-ondemand-family-subscriptions', [
            'new_subscription_id' => $newSubscription->id,
            'previous_subscription_id' => $previousSubscription->id,
        ]));
        $this->assertTrue($result);

        // Subscriptions are linked
        $meta = $this->subscriptionMetaRepository->getMeta($previousSubscription, FamilyRequests::NEXT_FAMILY_SUBSCRIPTION_META)->fetch();
        $this->assertEquals($newSubscription->id, $meta->value);

        // All 3 slave users have a new subscription from the new master
        $this->assertEquals(2, $this->subscriptionsRepository->userSubscriptions($slaveUser1->id)->count());
        $this->assertEquals(2, $this->subscriptionsRepository->userSubscriptions($slaveUser2->id)->count());
        $this->assertEquals(2, $this->subscriptionsRepository->userSubscriptions($slaveUser3->id)->count());

        $this->assertEquals(3, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($newSubscription)->count());
        // One on-demand request created by CreateOnDemandRequestEventHandler after the last activation
        $this->assertEquals(1, $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($newSubscription)->count());
    }

    public function testDoesNotLinkWhenNoPreviousActivations(): void
    {
        [$masterSubscriptionType] = $this->seedFamilySubscriptionTypes();
        $masterUser = $this->getUser('master@example.com');

        $previousSubscription = $this->createOnDemandSubscription($masterUser, $masterSubscriptionType);
        $newSubscription = $this->createOnDemandSubscription(
            $masterUser,
            $masterSubscriptionType,
            $this->getNow()->modify('+31 days'),
            $this->getNow()->modify('+62 days'),
        );

        $result = $this->renewHandler->handle(new HermesMessage('renew-ondemand-family-subscriptions', [
            'new_subscription_id' => $newSubscription->id,
            'previous_subscription_id' => $previousSubscription->id,
        ]));
        $this->assertTrue($result);

        $meta = $this->subscriptionMetaRepository->getMeta($previousSubscription, FamilyRequests::NEXT_FAMILY_SUBSCRIPTION_META)->fetch();
        $this->assertNull($meta);

        $this->assertEquals(0, $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($newSubscription)->count());
    }

    public function testCopiesNoteFromPreviousRequest(): void
    {
        [$masterSubscriptionType] = $this->seedFamilySubscriptionTypes();
        $masterUser = $this->getUser('master@example.com');
        $slaveUser = $this->getUser('slave@example.com');

        $previousSubscription = $this->createOnDemandSubscription($masterUser, $masterSubscriptionType);

        $request = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($previousSubscription)->fetch();
        $this->familyRequestsRepository->update($request, ['note' => 'VIP member']);
        $this->donateSubscription->connectFamilyUser($slaveUser, $this->familyRequestsRepository->find($request->id));

        $newSubscription = $this->createOnDemandSubscription(
            $masterUser,
            $masterSubscriptionType,
            $this->getNow()->modify('+31 days'),
            $this->getNow()->modify('+62 days'),
        );

        $this->renewHandler->handle(new HermesMessage('renew-ondemand-family-subscriptions', [
            'new_subscription_id' => $newSubscription->id,
            'previous_subscription_id' => $previousSubscription->id,
        ]));

        $newRequest = $this->familyRequestsRepository->masterSubscriptionAcceptedFamilyRequests($newSubscription)->fetch();
        $this->assertEquals('VIP member', $newRequest->note);
    }

    private function createOnDemandSubscription(
        ActiveRow $masterUser,
        ActiveRow $masterSubscriptionType,
        ?\DateTime $startDate = null,
        ?\DateTime $endDate = null,
    ): ActiveRow {
        $startDate ??= $this->getNow();
        $endDate ??= $this->getNow()->modify('+31 days');

        $subscriptions = $this->subscriptionsGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            $startDate,
            $endDate,
            true,
        ), 1);
        $subscription = $subscriptions[0];

        $this->subscriptionMetaRepository->add($subscription, FamilyRequests::ON_DEMAND_FAMILY_REQUESTS_SUBSCRIPTIONS_META, '1');
        $this->familyRequests->createFromSubscription($subscription);

        return $subscription;
    }

    private function activateOnDemandRequest(ActiveRow $masterSubscription, ActiveRow $slaveUser): void
    {
        $request = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($masterSubscription)->fetch();
        $this->assertNotNull($request, "No available on-demand request found for activation on subscription #{$masterSubscription->id}");
        $this->donateSubscription->connectFamilyUser($slaveUser, $request);
    }

    private function getUser(string $email): ActiveRow
    {
        return $this->userManager->addNewUser($email, false, 'unknown', null, false);
    }
}
