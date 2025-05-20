<?php

namespace Crm\FamilyModule\Tests;

use Crm\ApiModule\Tests\ApiTestTrait;
use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
use Crm\FamilyModule\Api\ActivateFamilyRequestApiHandler;
use Crm\FamilyModule\Events\NewSubscriptionHandler;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\SubscriptionsModule\Events\NewSubscriptionEvent;
use Crm\SubscriptionsModule\Models\Generator\SubscriptionsGenerator;
use Crm\SubscriptionsModule\Models\Generator\SubscriptionsParams;
use Crm\SubscriptionsModule\Repositories\SubscriptionMetaRepository;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Repositories\AccessTokensRepository;
use Crm\UsersModule\Tests\TestUserTokenAuthorization;
use Nette\Database\Table\ActiveRow;
use Nette\Http\Response;
use Nette\Utils\DateTime;
use Tomaj\NetteApi\Response\JsonApiResponse;

class ActivateFamilyRequestApiHandlerTest extends BaseTestCase
{
    use ApiTestTrait;

    private const COMPANY_SUBSCRIPTIONS_LENGTH = 31;
    private const COMPANY_SUBSCRIPTIONS_COUNT = 5;

    private AccessTokensRepository $accessTokensRepository;
    private DonateSubscription $donateSubscription;
    private LazyEventEmitter $lazyEventEmitter;
    private FamilyRequestsRepository $familyRequestsRepository;
    private SubscriptionsGenerator $subscriptionGenerator;
    private SubscriptionMetaRepository $subscriptionMetaRepository;
    private UserManager $userManager;
    private ActivateFamilyRequestApiHandler $handler;

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
        $this->subscriptionMetaRepository = $this->getRepository(SubscriptionMetaRepository::class);
        $this->userManager = $this->inject(UserManager::class);

        // To create family requests and renew family subscriptions
        $this->lazyEventEmitter->addListener(
            NewSubscriptionEvent::class,
            $this->inject(NewSubscriptionHandler::class),
        );

        // handler we want to test
        $this->handler = $this->inject(ActivateFamilyRequestApiHandler::class);
    }

    public function tearDown(): void
    {
        // reset NOW; it affects tests run after this class
        $this->donateSubscription->setNow(null);

        $this->lazyEventEmitter->removeAllListeners(NewSubscriptionEvent::class);

        parent::tearDown();
    }

    public function testActivateFamilyRequest()
    {
        [ , , $slaveUserWithoutAccepted, $familyRequests, ] = $this->prepareFamilyRequests();

        // select first unused code
        $testFamilyRequest = next($familyRequests);

        // call & test API
        $this->handler->setRawPayload(json_encode(['code' => $testFamilyRequest->code]));
        $this->handler->setDonateSubscriptionNow(new DateTime('2020-07-10'));
        $this->handler->setAuthorization($this->getTestAuthorization($slaveUserWithoutAccepted));

        $response = $this->runJsonApi($this->handler);

        $this->assertEquals(JsonApiResponse::class, get_class($response));
        $this->assertEquals(Response::S201_CREATED, $response->getCode());

        $payload = $response->getPayload();

        // check code & create subscription type and dates
        $this->assertEquals($payload['code'], $testFamilyRequest->code);
        $this->assertEquals($payload['subscription']['code'], $testFamilyRequest->subscription_type->code);

        // TODO: change all 'c' to DATE_ATOM or DATE_RFC3339
        // api returns dates with 'c' format; PHP is unable to parse it's own format; it must parse it as DATE_ATOM (WTF!)
        $returnedStartAt = DateTime::createFromFormat(DATE_ATOM, $payload['subscription']['start_at']);
        $returnedEndAt = DateTime::createFromFormat(DATE_ATOM, $payload['subscription']['end_at']);
        $this->assertEquals(31, $returnedStartAt->diff($returnedEndAt)->days);
        $this->assertEquals(['web', 'mobile'], $payload['subscription']['access']);
    }

    public function testMissingJsonPayload()
    {
        [ , ,$slaveUserWithoutAccepted, , ] = $this->prepareFamilyRequests();

        // call & test API
        $this->handler->setAuthorization($this->getTestAuthorization($slaveUserWithoutAccepted));
        $response = $this->runJsonApi($this->handler);
        $this->assertEquals(JsonApiResponse::class, get_class($response));

        $payload = $response->getPayload();
        $this->assertEquals(Response::S400_BAD_REQUEST, $response->getCode());
        $this->assertEquals('Empty request', $payload['message']);
    }

    public function testFamilyRequestCodeNotFound()
    {
        [ , ,$slaveUserWithoutAccepted, , ] = $this->prepareFamilyRequests();

        // call & test API
        $this->handler->setRawPayload(json_encode(['code' => 'invalid_family_request_code']));
        $this->handler->setAuthorization($this->getTestAuthorization($slaveUserWithoutAccepted));
        $response = $this->runJsonApi($this->handler);
        $this->assertEquals(JsonApiResponse::class, get_class($response));

        $payload = $response->getPayload();
        $this->assertEquals(Response::S404_NOT_FOUND, $response->getCode());
        $this->assertEquals('family_request_not_found', $payload['code']);
    }

    public function testFamilyRequestCodeExpired()
    {
        [ , ,$slaveUserWithoutAccepted, $familyRequests, ] = $this->prepareFamilyRequests();

        // select first unused code
        $testFamilyRequest = reset($familyRequests);
        // update expires_at to past
        $this->familyRequestsRepository->update(
            $testFamilyRequest,
            ['expires_at' => new DateTime('2020-06-10')],
        );

        // call & test API
        $this->handler->setRawPayload(json_encode(['code' => $testFamilyRequest->code]));
        $this->handler->setDonateSubscriptionNow(new DateTime('2020-07-10'));
        $this->handler->setAuthorization($this->getTestAuthorization($slaveUserWithoutAccepted));
        $response = $this->runJsonApi($this->handler);
        $this->assertEquals(JsonApiResponse::class, get_class($response));

        $payload = $response->getPayload();
        $this->assertEquals(Response::S400_BAD_REQUEST, $response->getCode());
        $this->assertEquals('family_request_expired', $payload['code']);
    }

    public function testActivateSecondCodeForUserWhoAlreadyUsedCode()
    {
        [ , $slaveUserWithAccepted, , $familyRequests, ] = $this->prepareFamilyRequests();

        // select first unused code
        $testFamilyRequest = reset($familyRequests);

        // call & test API
        $this->handler->setRawPayload(json_encode(['code' => $testFamilyRequest->code]));
        $this->handler->setDonateSubscriptionNow(new DateTime('2020-07-10'));
        $this->handler->setAuthorization($this->getTestAuthorization($slaveUserWithAccepted));
        $response = $this->runJsonApi($this->handler);
        $this->assertEquals(JsonApiResponse::class, get_class($response));

        $payload = $response->getPayload();
        $this->assertEquals(Response::S400_BAD_REQUEST, $response->getCode());
        $this->assertEquals('family_request_one_per_user', $payload['code']);
    }

    public function testForbiddenActivationByMasterUser()
    {
        [$masterUser, , , $familyRequests, ] = $this->prepareFamilyRequests();

        // select first unused code
        $testFamilyRequest = reset($familyRequests);

        // set special flag in master subscription's meta (this disallows self activation by master user; not sure why, this should be probable renamed / refactored)
        $this->subscriptionMetaRepository->add(
            $testFamilyRequest->master_subscription,
            'family_subscription_type',
            'days',
        );

        // call & test API
        $this->handler->setRawPayload(json_encode(['code' => $testFamilyRequest->code]));
        $this->handler->setAuthorization($this->getTestAuthorization($masterUser));
        $response = $this->runJsonApi($this->handler);
        $this->assertEquals(JsonApiResponse::class, get_class($response));

        $payload = $response->getPayload();
        $this->assertEquals(Response::S400_BAD_REQUEST, $response->getCode());
        $this->assertEquals('family_request_self_use_forbidden', $payload['code']);
    }

    public function testActivationOfAlreadyActivatedRequest()
    {
        [$masterUser, , , , $acceptedFamilyRequest] = $this->prepareFamilyRequests();

        $this->handler->setRawPayload(json_encode(['code' => $acceptedFamilyRequest->code]));
        $this->handler->setAuthorization($this->getTestAuthorization($masterUser));
        $response = $this->runJsonApi($this->handler);
        $this->assertEquals(JsonApiResponse::class, get_class($response));
        $this->assertEquals(Response::S409_CONFLICT, $response->getCode());

        $payload = $response->getPayload();
        $this->assertEquals('family_request_wrong_status', $payload['code']);
    }

    private function prepareFamilyRequests(): array
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes(
            self::COMPANY_SUBSCRIPTIONS_LENGTH,
            self::COMPANY_SUBSCRIPTIONS_COUNT,
        );

        $masterUser = $this->createUser('master@example.com');
        $slaveUserWithAccepted = $this->createUser('slave_with_accepted@example.com');
        $slaveUserWithoutAccepted = $this->createUser('slave_without_accepted@example.com');

        // generate master subscription + handler generates family requests
        $masterSubscription = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            'family',
            new DateTime('2020-07-01'),
            new DateTime('2020-08-01'),
            true,
        ), 1);

        // check number of generated requests
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($masterSubscription[0])->fetchAll();
        $this->assertCount(self::COMPANY_SUBSCRIPTIONS_COUNT, $familyRequests);

        // donate one subscription to slave user & reload change
        $acceptedFamilyRequest = reset($familyRequests);
        $this->donateSubscription->setNow(new \DateTime('2020-07-10'));
        $this->donateSubscription->connectFamilyUser(
            $slaveUserWithAccepted,
            $acceptedFamilyRequest,
        );

        // reload & return
        $acceptedFamilyRequest = $this->familyRequestsRepository->findByCode($acceptedFamilyRequest->code);
        $familyRequests = $this->familyRequestsRepository->userFamilyRequest($masterUser)->fetchAll();

        return [$masterUser, $slaveUserWithAccepted, $slaveUserWithoutAccepted, $familyRequests, $acceptedFamilyRequest];
    }

    private function createUser($email)
    {
        return $this->userManager->addNewUser(
            $email,
            false,
            'unknown',
            null,
            false,
        );
    }

    private function getTestAuthorization(ActiveRow $user)
    {
        $token = $this->accessTokensRepository->allUserTokens($user->id)->limit(1)->fetch();
        return new TestUserTokenAuthorization($token, $user);
    }
}
