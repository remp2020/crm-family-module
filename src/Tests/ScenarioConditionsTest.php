<?php

namespace Crm\FamilyModule\Tests;

use Contributte\Translation\Translator;
use Crm\ApplicationModule\Criteria\ScenariosCriteriaStorage;
use Crm\FamilyModule\Events\NewSubscriptionHandler;
use Crm\FamilyModule\FamilyModule;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\FamilyModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\FamilyModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\ScenariosModule\Repositories\ElementsRepository;
use Crm\ScenariosModule\Repositories\ScenariosRepository;
use Crm\ScenariosModule\Repositories\TriggersRepository;
use Crm\ScenariosModule\Tests\BaseTestCase as ScenariosBaseTestCase;
use Crm\SubscriptionsModule\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Events\NewSubscriptionEvent;
use Crm\SubscriptionsModule\Generator\SubscriptionsGenerator;
use Crm\SubscriptionsModule\Generator\SubscriptionsParams;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UsersModule\Auth\UserManager;
use Nette\Utils\DateTime;

class ScenarioConditionsTest extends ScenariosBaseTestCase
{
    use SeedFamilySubscriptionTypesTrait;

    const EMAIL_TEMPLATE_SUCCESS = 'success_email';
    const EMAIL_TEMPLATE_FAIL = 'fail_email';

    /** @var UserManager */
    private $userManager;

    /** @var SubscriptionTypeBuilder */
    private $subscriptionTypeBuilder;

    /** @var SubscriptionsGenerator */
    private $subscriptionGenerator;

    /** @var SubscriptionsRepository */
    private $subscriptionRepository;

    /** @var ScenariosCriteriaStorage */
    private $scenariosCriteriaStorage;

    /** @var FamilySubscriptionTypesRepository */
    private $familySubscriptionTypesRepository;

    /** @var FamilyRequestsRepository */
    private $familyRequestsRepository;

    /** @var DonateSubscription */
    private $donateSubscription;

    protected function requiredRepositories(): array
    {
        return array_merge(parent::requiredRepositories(), [
            FamilySubscriptionTypesRepository::class,
            FamilyRequestsRepository::class,
        ]);
    }

    protected function requiredSeeders(): array
    {
        return array_merge(parent::requiredSeeders(), [
            SubscriptionExtensionMethodsSeeder::class,
            SubscriptionTypeNamesSeeder::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->userManager = $this->inject(UserManager::class);
        $this->subscriptionGenerator = $this->inject(SubscriptionsGenerator::class);

        $this->donateSubscription = $this->inject(DonateSubscription::class);

        $this->subscriptionTypeBuilder = $this->inject(SubscriptionTypeBuilder::class);
        $this->familySubscriptionTypesRepository = $this->getRepository(FamilySubscriptionTypesRepository::class);
        $this->familyRequestsRepository = $this->getRepository(FamilyRequestsRepository::class);

        // Register modules' scenarios criteria storage
        $this->scenariosCriteriaStorage = $this->inject(ScenariosCriteriaStorage::class);
        $m = new FamilyModule($this->container, $this->inject(Translator::class), $this->inject(FamilyRequests::class));
        $m->registerScenariosCriteria($this->scenariosCriteriaStorage);

        // To create family requests and renew family subscriptions
        $this->lazyEventEmitter->addListener(NewSubscriptionEvent::class, $this->inject(NewSubscriptionHandler::class));
    }

    protected function tearDown(): void
    {
        $this->lazyEventEmitter->removeAllListeners(NewSubscriptionEvent::class);

        parent::tearDown();
    }

    /**
     * Test scenario with flows:
     * TRIGGER (master subscription) -> CONDITION (isFamilyMaster) -> MAIL (positive)
     * TRIGGER (slave subscription) -> CONDITION (isFamilyMaster) -> MAIL (negative)
     */
    public function testIsFamilyMasterCondition(): void
    {
        $this->getRepository(ScenariosRepository::class)->createOrUpdate([
            'name' => 'test1',
            'enabled' => true,
            'triggers' => [
                self::obj([
                    'name' => '',
                    'type' => TriggersRepository::TRIGGER_TYPE_EVENT,
                    'id' => 'trigger1',
                    'event' => ['code' => 'new_subscription'],
                    'elements' => ['element_condition']
                ])
            ],
            'elements' => [
                self::obj([
                    'name' => '',
                    'id' => 'element_condition',
                    'type' => ElementsRepository::ELEMENT_TYPE_CONDITION,
                    'condition' => [
                        'descendants' => [
                            ['uuid' => 'element_email_pos', 'direction' => 'positive'],
                            ['uuid' => 'element_email_neg', 'direction' => 'negative']
                        ],
                        'conditions' => [
                            'event' => 'subscription',
                            'version' => 1,
                            'nodes' => [
                                [
                                    'key' => 'is_family_master',
                                    'params' => [
                                        [
                                            'key' => 'is_family_master',
                                            'values' => [
                                                'selection' => true
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]),
                self::obj([
                    'name' => '',
                    'id' => 'element_email_pos',
                    'type' => ElementsRepository::ELEMENT_TYPE_EMAIL,
                    'email' => ['code' => self::EMAIL_TEMPLATE_SUCCESS]
                ]),
                self::obj([
                    'name' => '',
                    'id' => 'element_email_neg',
                    'type' => ElementsRepository::ELEMENT_TYPE_EMAIL,
                    'email' => ['code' => self::EMAIL_TEMPLATE_FAIL]
                ])
            ]
        ]);

        $masterUser = $this->userManager->addNewUser('master@email.com', false, 'unknown', null, false);
        $slaveUser = $this->userManager->addNewUser('slave@email.com', false, 'unknown', null, false);

        // Add new subscription, which triggers scenario
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterSubscriptions = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            SubscriptionsRepository::TYPE_FREE,
            new DateTime(),
            null,
            false
        ), 1);

        // SIMULATE RUN
        $this->dispatcher->handle(); // run Hermes to create trigger job
        $this->engine->run(3); // process trigger, finish its job, create condition job and schedule it
        $this->dispatcher->handle(); // job(cond): scheduled -> started -> finished
        $this->engine->run(3); // job(cond): deleted, job(email): created and scheduled
        $this->dispatcher->handle(); // job(email): scheduled -> started -> finished
        $this->engine->run(1); // job(email): deleted

        // Check positive email was sent
        $mails = $this->mailsSentTo('master@email.com');
        $this->assertCount(1, $mails);
        $this->assertEquals(self::EMAIL_TEMPLATE_SUCCESS, $mails[0]);

        // Now donate slave subscription, condition element should be evaluated as false
        $requests = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($masterSubscriptions[0])->fetchAll();
        $request = current($requests);

        // Triggers scenario
        $this->donateSubscription->connectFamilyUser($slaveUser, $request);

        // SIMULATE RUN
        $this->dispatcher->handle(); // run Hermes to create trigger job
        $this->engine->run(3); // process trigger, finish its job, create condition job and schedule it
        $this->dispatcher->handle(); // job(cond): scheduled -> started -> finished
        $this->engine->run(3); // job(cond): deleted, job(email): created and scheduled
        $this->dispatcher->handle(); // job(email): scheduled -> started -> finished
        $this->engine->run(1); // job(email): deleted

        // Check negative email was sent
        $mails = $this->mailsSentTo('slave@email.com');
        $this->assertCount(1, $mails);
        $this->assertEquals(self::EMAIL_TEMPLATE_FAIL, $mails[0]);
    }

    /**
     * Test scenario with flows:
     * TRIGGER (master subscription) -> CONDITION (isFamilySlave) -> MAIL (negative)
     * TRIGGER (slave subscription) -> CONDITION (isFamilySlave) -> MAIL (positive)
     */
    public function testIsFamilySlaveCondition(): void
    {
        $this->getRepository(ScenariosRepository::class)->createOrUpdate([
            'name' => 'test1',
            'enabled' => true,
            'triggers' => [
                self::obj([
                    'name' => '',
                    'type' => TriggersRepository::TRIGGER_TYPE_EVENT,
                    'id' => 'trigger1',
                    'event' => ['code' => 'new_subscription'],
                    'elements' => ['element_condition']
                ])
            ],
            'elements' => [
                self::obj([
                    'name' => '',
                    'id' => 'element_condition',
                    'type' => ElementsRepository::ELEMENT_TYPE_CONDITION,
                    'condition' => [
                        'descendants' => [
                            ['uuid' => 'element_email_pos', 'direction' => 'positive'],
                            ['uuid' => 'element_email_neg', 'direction' => 'negative']
                        ],
                        'conditions' => [
                            'event' => 'subscription',
                            'version' => 1,
                            'nodes' => [
                                [
                                    'key' => 'is_family_slave',
                                    'params' => [
                                        [
                                            'key' => 'is_family_slave',
                                            'values' => [
                                                'selection' => true
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]),
                self::obj([
                    'name' => '',
                    'id' => 'element_email_pos',
                    'type' => ElementsRepository::ELEMENT_TYPE_EMAIL,
                    'email' => ['code' => self::EMAIL_TEMPLATE_SUCCESS]
                ]),
                self::obj([
                    'name' => '',
                    'id' => 'element_email_neg',
                    'type' => ElementsRepository::ELEMENT_TYPE_EMAIL,
                    'email' => ['code' => self::EMAIL_TEMPLATE_FAIL]
                ])
            ]
        ]);

        $masterUser = $this->userManager->addNewUser('master@email.com', false, 'unknown', null, false);
        $slaveUser = $this->userManager->addNewUser('slave@email.com', false, 'unknown', null, false);

        // Add new subscription, which triggers scenario
        [$masterSubscriptionType, ] = $this->seedFamilySubscriptionTypes();

        $masterSubscriptions = $this->subscriptionGenerator->generate(new SubscriptionsParams(
            $masterSubscriptionType,
            $masterUser,
            SubscriptionsRepository::TYPE_FREE,
            new DateTime(),
            null,
            false
        ), 1);

        // SIMULATE RUN
        $this->dispatcher->handle(); // run Hermes to create trigger job
        $this->engine->run(3); // process trigger, finish its job, create condition job and schedule it
        $this->dispatcher->handle(); // job(cond): scheduled -> started -> finished
        $this->engine->run(3); // job(cond): deleted, job(email): created and scheduled
        $this->dispatcher->handle(); // job(email): scheduled -> started -> finished
        $this->engine->run(1); // job(email): deleted

        // Check positive email was sent
        $mails = $this->mailsSentTo('master@email.com');
        $this->assertCount(1, $mails);
        $this->assertEquals(self::EMAIL_TEMPLATE_FAIL, $mails[0]);

        // Now donate slave subscription, condition element should be evaluated as false
        $request = $this->familyRequestsRepository->masterSubscriptionUnusedFamilyRequests($masterSubscriptions[0])->fetch();

        // Triggers scenario
        $this->donateSubscription->connectFamilyUser($slaveUser, $request);

        // SIMULATE RUN
        $this->dispatcher->handle(); // run Hermes to create trigger job
        $this->engine->run(3); // process trigger, finish its job, create condition job and schedule it
        $this->dispatcher->handle(); // job(cond): scheduled -> started -> finished
        $this->engine->run(3); // job(cond): deleted, job(email): created and scheduled
        $this->dispatcher->handle(); // job(email): scheduled -> started -> finished
        $this->engine->run(1); // job(email): deleted

        // Check negative email was sent
        $mails = $this->mailsSentTo('slave@email.com');
        $this->assertCount(1, $mails);
        $this->assertEquals(self::EMAIL_TEMPLATE_SUCCESS, $mails[0]);
    }
}
