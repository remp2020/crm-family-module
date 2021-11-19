<?php

namespace Crm\FamilyModule\Tests;

use Crm\ApiModule\Api\JsonResponse;
use Crm\FamilyModule\Api\ActivateFamilyRequestApiHandler;
use Crm\FamilyModule\Events\NewSubscriptionHandler;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\SubscriptionsModule\Events\NewSubscriptionEvent;
use Crm\SubscriptionsModule\Generator\SubscriptionsGenerator;
use Crm\SubscriptionsModule\Generator\SubscriptionsParams;
use Crm\SubscriptionsModule\Repository\SubscriptionMetaRepository;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Repository\AccessTokensRepository;
use Crm\UsersModule\Tests\TestUserTokenAuthorization;
use League\Event\Emitter;
use Nette\Database\Table\IRow;
use Nette\Http\Response;
use Nette\Utils\DateTime;

class ActivateFamilyRequestApiHandlerTest extends BaseTestCase
{
    private const COMPANY_SUBSCRIPTIONS_LENGTH = 31;
    private const COMPANY_SUBSCRIPTIONS_COUNT = 5;

    /** @var AccessTokensRepository */
    private $accessTokensRepository;

    /** @var DonateSubscription */
    private $donateSubscription;

    /** @var Emitter */
    private $emitter;

    /** @var FamilyRequestsRepository */
    private $familyRequestsRepository;

    /** @var ActivateFamilyRequestApiHandler */
    private $handler;

    /** @var SubscriptionsGenerator */
    private $subscriptionGenerator;

    /** @var SubscriptionMetaRepository */
    private $subscriptionMetaRepository;

    /** @var UserManager */
    private $userManager;

    public function requiredRepositories(): array
    {
        return array_merge(
            [
                AccessTokensRepository::class,
            ],
            parent::requiredRepositories()
        );
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->accessTokensRepository = $this->getRepository(AccessTokensRepository::class);
        $this->donateSubscription = $this->inject(DonateSubscription::class);
        $this->emitter = $this->inject(Emitter::class);
        $this->familyRequestsRepository = $this->inject(FamilyRequestsRepository::class);
        $this->subscriptionGenerator = $this->inject(SubscriptionsGenerator::class);
        $this->subscriptionMetaRepository = $this->getRepository(SubscriptionMetaRepository::class);
        $this->userManager = $this->inject(UserManager::class);

        // To create family requests and renew family subscriptions
        $this->emitter->addListener(NewSubscriptionEvent::class, $this->inject(NewSubscriptionHandler::class));

        // handler we want to test
        $this->handler = $this->inject(ActivateFamilyRequestApiHandler::class);
    }

    public function tearDown(): void
    {
        // reset NOW; it affects tests run after this class
        $this->donateSubscription->setNow(null);

        $this->emitter->removeListener(NewSubscriptionEvent::class, $this->inject(NewSubscriptionHandler::class));

        parent::tearDown();
    }

    public function testActivateFamilyRequest()
    {
        [ , , $slaveUserWithoutAccepted, $familyRequests, ] = $this->prepareFamilyRequests();

        // select first unused code
        $testFamilyRequest = reset($familyRequests);

        // call & test API
        $this->handler->setRawPayload(json_encode(['code' => $testFamilyRequest->code]));
        $this->handler->setDonateSubscriptionNow(new DateTime('2020-07-10'));
        $response = $this->handler->handle($this->getTestAuthorization($slaveUserWithoutAccepted));
        $this->assertEquals(JsonResponse::class, get_class($response));
        $this->assertEquals(Response::S201_CREATED, $response->getHttpCode());

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
        $response = $this->handler->handle($this->getTestAuthorization($slaveUserWithoutAccepted));
        $this->assertEquals(JsonResponse::class, get_class($response));

        $payload = $response->getPayload();
        $this->assertEquals(Response::S400_BAD_REQUEST, $response->getHttpCode());
        $this->assertEquals('Empty request', $payload['message']);
    }

    public function testFamilyRequestCodeNotFound()
    {
        [ , ,$slaveUserWithoutAccepted, , ] = $this->prepareFamilyRequests();

        // call & test API
        $this->handler->setRawPayload(json_encode(['code' => 'invalid_family_request_code']));
        $response = $this->handler->handle($this->getTestAuthorization($slaveUserWithoutAccepted));
        $this->assertEquals(JsonResponse::class, get_class($response));

        $payload = $response->getPayload();
        $this->assertEquals(Response::S404_NOT_FOUND, $response->getHttpCode());
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
            ['expires_at' => new DateTime('2020-06-10')]
        );

        // call & test API
        $this->handler->setRawPayload(json_encode(['code' => $testFamilyRequest->code]));
        $this->handler->setDonateSubscriptionNow(new DateTime('2020-07-10'));
        $response = $this->handler->handle($this->getTestAuthorization($slaveUserWithoutAccepted));
        $this->assertEquals(JsonResponse::class, get_class($response));

        $payload = $response->getPayload();
        $this->assertEquals(Response::S400_BAD_REQUEST, $response->getHttpCode());
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
        $response = $this->handler->handle($this->getTestAuthorization($slaveUserWithAccepted));
        $this->assertEquals(JsonResponse::class, get_class($response));

        $payload = $response->getPayload();
        $this->assertEquals(Response::S400_BAD_REQUEST, $response->getHttpCode());
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
            'days'
        );

        // call & test API
        $this->handler->setRawPayload(json_encode(['code' => $testFamilyRequest->code]));
        $response = $this->handler->handle($this->getTestAuthorization($masterUser));
        $this->assertEquals(JsonResponse::class, get_class($response));

        $payload = $response->getPayload();
        $this->assertEquals(Response::S400_BAD_REQUEST, $response->getHttpCode());
        $this->assertEquals('family_request_self_use_forbidden', $payload['code']);
    }

    private function prepareFamilyRequests(): array
    {
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes(
            self::COMPANY_SUBSCRIPTIONS_LENGTH,
            self::COMPANY_SUBSCRIPTIONS_COUNT
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
            true
        ), 1);

        // check number of generated requests
        $familyRequests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($masterSubscription[0])->fetchAll();
        $this->assertCount(self::COMPANY_SUBSCRIPTIONS_COUNT, $familyRequests);

        // donate one subscription to slave user & reload change
        $acceptedFamilyRequest = reset($familyRequests);
        $this->donateSubscription->setNow(new \DateTime('2020-07-10'));
        $this->donateSubscription->connectFamilyUser(
            $slaveUserWithAccepted,
            $acceptedFamilyRequest
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
            false
        );
    }

    private function getTestAuthorization(IRow $user)
    {
        $token = $this->accessTokensRepository->allUserTokens($user->id)->limit(1)->fetch();
        return new TestUserTokenAuthorization($token);
    }
}
