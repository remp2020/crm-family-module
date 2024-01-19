<?php

namespace Crm\FamilyModule\Tests;

use Crm\ApiModule\Tests\ApiTestTrait;
use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
use Crm\FamilyModule\Api\ListFamilyRequestsApiHandler;
use Crm\FamilyModule\Events\NewSubscriptionHandler;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\SubscriptionsModule\Events\NewSubscriptionEvent;
use Crm\SubscriptionsModule\Models\Generator\SubscriptionsGenerator;
use Crm\SubscriptionsModule\Models\Generator\SubscriptionsParams;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Repositories\AccessTokensRepository;
use Crm\UsersModule\Tests\TestUserTokenAuthorization;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Tomaj\NetteApi\Response\JsonApiResponse;

class ListFamilyRequestsApiHandlerTest extends BaseTestCase
{
    use ApiTestTrait;

    private const COMPANY_SUBSCRIPTIONS_LENGTH = 31;
    private const COMPANY_SUBSCRIPTIONS_COUNT = 5;

    private AccessTokensRepository $accessTokensRepository;
    private DonateSubscription $donateSubscription;
    private LazyEventEmitter $lazyEventEmitter;
    private FamilyRequestsRepository $familyRequestsRepository;
    private ListFamilyRequestsApiHandler $handler;
    private SubscriptionsGenerator $subscriptionGenerator;
    private UserManager $userManager;

    public function requiredRepositories(): array
    {
        return array_merge(parent::requiredRepositories(), [
            AccessTokensRepository::class,
        ]);
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->accessTokensRepository = $this->getRepository(AccessTokensRepository::class);
        $this->donateSubscription = $this->inject(DonateSubscription::class);
        $this->lazyEventEmitter = $this->inject(LazyEventEmitter::class);
        $this->familyRequestsRepository = $this->inject(FamilyRequestsRepository::class);
        $this->subscriptionGenerator = $this->inject(SubscriptionsGenerator::class);
        $this->userManager = $this->inject(UserManager::class);

        // To create family requests and renew family subscriptions
        $this->lazyEventEmitter->addListener(NewSubscriptionEvent::class, $this->inject(NewSubscriptionHandler::class));


        // handler we want to test
        $this->handler = $this->inject(ListFamilyRequestsApiHandler::class);
    }

    protected function tearDown(): void
    {
        $this->lazyEventEmitter->removeAllListeners(NewSubscriptionEvent::class);

        parent::tearDown();
    }

    public function testListExistingFamilyRequests()
    {
        [$masterUser, $slaveUser, $acceptedFamilyRequest] = $this->prepareFamilyRequests();

        // call & test API
        $this->handler->setAuthorization($this->getTestAuthorization($masterUser));
        $response = $this->runJsonApi($this->handler);
        $this->assertEquals(JsonApiResponse::class, get_class($response));

        $payload = $response->getPayload();

        $this->assertCount(self::COMPANY_SUBSCRIPTIONS_COUNT, $payload['codes']);

        // find all accepted codes & assert (only one should be accepted)
        $numberOfAccepted = 1;
        $numberOfAcceptedInApi = 0;
        foreach ($payload['codes'] as $request) {
            if ($request['accepted_at'] !== null) {
                $this->assertEquals($acceptedFamilyRequest->code, $request['code']);
                $this->assertEquals($acceptedFamilyRequest->accepted_at->format('c'), $request['accepted_at']);
                $numberOfAcceptedInApi++;
            }
        }
        $this->assertEquals($numberOfAccepted, $numberOfAcceptedInApi);
    }

    public function testListExistingFamilyRequestsIncorrectUser()
    {
        [$masterUser, $slaveUser, $acceptedFamilyRequest] = $this->prepareFamilyRequests();

        // call & test API (with non master user)
        $this->handler->setAuthorization($this->getTestAuthorization($slaveUser));
        $response = $this->runJsonApi($this->handler);
        $this->assertEquals(JsonApiResponse::class, get_class($response));

        $payload = $response->getPayload();

        $this->assertCount(0, $payload['codes']);
    }

    private function prepareFamilyRequests(): array
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes(
            self::COMPANY_SUBSCRIPTIONS_LENGTH,
            self::COMPANY_SUBSCRIPTIONS_COUNT
        );

        $masterUser = $this->createUser('master@example.com');
        $slaveUser = $this->createUser('slave@example.com');

        // generate master subscription + handler generates family requests
        $masterSubscription = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            new DateTime('now - 1 days'),
            new DateTime('now + 30 days'),
            true
        ), 1);

        // check number of generated requests (based on
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($masterSubscription[0])->fetchAll();
        $this->assertCount(self::COMPANY_SUBSCRIPTIONS_COUNT, $familyRequests);

        // donate one subscription to slave user & reload change
        $acceptedFamilyRequest = reset($familyRequests);
        $this->donateSubscription->connectFamilyUser(
            $slaveUser,
            $acceptedFamilyRequest
        );
        $acceptedFamilyRequest = $this->familyRequestsRepository->findByCode($acceptedFamilyRequest->code);

        return [$masterUser, $slaveUser, $acceptedFamilyRequest];
    }

    private function createUser($email)
    {
        return $this->userManager->addNewUser(
            $email,
            false,
            'unknown',
            null,
            false
        );
    }

    private function getTestAuthorization(ActiveRow $user)
    {
        $token = $this->accessTokensRepository->allUserTokens($user->id)->limit(1)->fetch();
        return new TestUserTokenAuthorization($token);
    }
}
