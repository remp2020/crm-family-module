<?php

namespace Crm\FamilyModule;

use Contributte\Translation\Translator;
use Crm\ApiModule\Models\Api\ApiRoutersContainerInterface;
use Crm\ApiModule\Models\Router\ApiIdentifier;
use Crm\ApiModule\Models\Router\ApiRoute;
use Crm\ApplicationModule\Application\CommandsContainerInterface;
use Crm\ApplicationModule\Application\Managers\SeederManager;
use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\Models\Criteria\ScenariosCriteriaStorage;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Models\Event\EventsStorage;
use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManagerInterface;
use Crm\FamilyModule\Api\ActivateFamilyRequestApiHandler;
use Crm\FamilyModule\Api\ListFamilyRequestsApiHandler;
use Crm\FamilyModule\Commands\GenerateFamilyRequestsCommand;
use Crm\FamilyModule\Components\FamilyRequestsDashboardWidget\FamilyRequestsDashboardWidget;
use Crm\FamilyModule\Components\FamilySubscriptionTypeDetailsWidget\FamilySubscriptionTypeDetailsWidget;
use Crm\FamilyModule\Components\MasterFamilySubscriptionInfoWidget\MasterFamilySubscriptionInfoWidget;
use Crm\FamilyModule\Components\SlaveFamilySubscriptionInfoWidget\SlaveFamilySubscriptionInfoWidget;
use Crm\FamilyModule\Components\UsersAbusiveAdditionalWidget\UsersAbusiveAdditionalWidget;
use Crm\FamilyModule\DataProviders\RecurrentPaymentPaymentItemContainerDataProvider;
use Crm\FamilyModule\DataProviders\SubscriptionTransferDataProvider;
use Crm\FamilyModule\Events\BeforeCreateRenewalPaymentEventHandler;
use Crm\FamilyModule\Events\FamilyRequestAcceptedEvent;
use Crm\FamilyModule\Events\FamilyRequestCreatedEvent;
use Crm\FamilyModule\Events\NewSubscriptionHandler;
use Crm\FamilyModule\Events\SubscriptionShortenedHandler;
use Crm\FamilyModule\Events\SubscriptionUpdatedHandler;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Models\Scenarios\IsFamilyMasterCriteria;
use Crm\FamilyModule\Models\Scenarios\IsFamilySlaveCriteria;
use Crm\FamilyModule\Seeders\FamilySeeder;
use Crm\FamilyModule\Seeders\MeasurementsSeeder;
use Crm\FamilyModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\FamilyModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\PaymentsModule\DataProviders\RecurrentPaymentPaymentItemContainerDataProviderInterface;
use Crm\PaymentsModule\Events\BeforeCreateRenewalPaymentEvent;
use Crm\SubscriptionsModule\Events\NewSubscriptionEvent;
use Crm\SubscriptionsModule\Events\SubscriptionShortenedEvent;
use Crm\SubscriptionsModule\Events\SubscriptionUpdatedEvent;
use Crm\UsersModule\Models\Auth\UserTokenAuthorization;
use Nette\DI\Container;
use Symfony\Component\Console\Output\OutputInterface;

class FamilyModule extends CrmModule
{
    public const SUBSCRIPTION_TYPE_FAMILY = 'family';

    private $familyRequests;

    public function __construct(
        Container $container,
        Translator $translator,
        FamilyRequests $familyRequests,
    ) {
        parent::__construct($container, $translator);

        $this->familyRequests = $familyRequests;
    }

    public function registerCommands(CommandsContainerInterface $commandsContainer)
    {
        $commandsContainer->registerCommand($this->getInstance(GenerateFamilyRequestsCommand::class));
    }

    public function registerApiCalls(ApiRoutersContainerInterface $apiRoutersContainer)
    {
        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'family', 'list'),
                ListFamilyRequestsApiHandler::class,
                UserTokenAuthorization::class,
            ),
        );
        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'family', 'activate'),
                ActivateFamilyRequestApiHandler::class,
                UserTokenAuthorization::class,
            ),
        );
    }

    public function registerLazyEventHandlers(LazyEventEmitter $emitter)
    {
        $emitter->addListener(
            NewSubscriptionEvent::class,
            NewSubscriptionHandler::class,
        );
        $emitter->addListener(
            SubscriptionShortenedEvent::class,
            SubscriptionShortenedHandler::class,
        );
        $emitter->addListener(
            SubscriptionUpdatedEvent::class,
            SubscriptionUpdatedHandler::class,
        );
        $emitter->addListener(
            BeforeCreateRenewalPaymentEvent::class,
            BeforeCreateRenewalPaymentEventHandler::class,
        );
    }

    public function registerDataProviders(DataProviderManager $dataProviderManager)
    {
        $dataProviderManager->registerDataProvider(
            RecurrentPaymentPaymentItemContainerDataProviderInterface::PATH,
            $this->getInstance(RecurrentPaymentPaymentItemContainerDataProvider::class),
        );
        $dataProviderManager->registerDataProvider(
            'subscriptions.dataprovider.transfer',
            $this->getInstance(SubscriptionTransferDataProvider::class),
        );
    }

    public function registerLazyWidgets(LazyWidgetManagerInterface $widgetManager)
    {
        $widgetManager->registerWidget(
            'admin.user.abusive.additional',
            UsersAbusiveAdditionalWidget::class,
        );

        $widgetManager->registerWidget(
            'dashboard.simplewidget.additional',
            FamilyRequestsDashboardWidget::class,
        );

        $widgetManager->registerWidget(
            'admin.user.detail.center',
            MasterFamilySubscriptionInfoWidget::class,
        );

        $widgetManager->registerWidget(
            'admin.user.detail.center',
            SlaveFamilySubscriptionInfoWidget::class,
        );

        $widgetManager->registerWidget(
            'subscription_types_admin.show.right',
            FamilySubscriptionTypeDetailsWidget::class,
            200,
        );
    }

    public function registerSeeders(SeederManager $seederManager)
    {
        $seederManager->addSeeder($this->getInstance(FamilySeeder::class));
        $seederManager->addSeeder($this->getInstance(SubscriptionExtensionMethodsSeeder::class));
        $seederManager->addSeeder($this->getInstance(SubscriptionTypeNamesSeeder::class));
        $seederManager->addSeeder($this->getInstance(MeasurementsSeeder::class));
    }

    public function registerScenariosCriteria(ScenariosCriteriaStorage $scenariosCriteriaStorage)
    {
        $scenariosCriteriaStorage->register('subscription', 'is_family_master', $this->getInstance(IsFamilyMasterCriteria::class));
        $scenariosCriteriaStorage->register('subscription', 'is_family_slave', $this->getInstance(IsFamilySlaveCriteria::class));
    }

    public function registerEvents(EventsStorage $eventsStorage)
    {
        $eventsStorage->register('family_request_created', FamilyRequestCreatedEvent::class, true);
        $eventsStorage->register('family_request_accepted', FamilyRequestAcceptedEvent::class, true);
    }

    public function cache(OutputInterface $output, array $tags = [])
    {
        if (in_array('precalc', $tags, true)) {
            $output->writeln('  * Refreshing <info>subscriptions stats</info> cache');

            $this->familyRequests->activeFamilyOwnersCount(true, true);
            $this->familyRequests->activeFamilyRequestsCount(true, true);
            $this->familyRequests->activePaidSubscribersWithFamilyRequestsCount(true, true);
        }
    }
}
