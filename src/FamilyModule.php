<?php

namespace Crm\FamilyModule;

use Crm\ApiModule\Api\ApiRoutersContainerInterface;
use Crm\ApiModule\Router\ApiIdentifier;
use Crm\ApiModule\Router\ApiRoute;
use Crm\ApplicationModule\Criteria\ScenariosCriteriaStorage;
use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\SeederManager;
use Crm\ApplicationModule\Widget\WidgetManagerInterface;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Models\Scenarios\IsFamilyMasterCriteria;
use Crm\FamilyModule\Models\Scenarios\IsFamilySlaveCriteria;
use Crm\FamilyModule\Seeders\FamilySeeder;
use Crm\FamilyModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\FamilyModule\Seeders\SubscriptionTypeNamesSeeder;
use Kdyby\Translation\Translator;
use League\Event\Emitter;
use Nette\DI\Container;
use Symfony\Component\Console\Output\OutputInterface;

class FamilyModule extends CrmModule
{
    public const SUBSCRIPTION_TYPE_FAMILY = 'family';

    private $familyRequests;

    public function __construct(
        Container $container,
        Translator $translator,
        FamilyRequests $familyRequests
    ) {
        parent::__construct($container, $translator);

        $this->familyRequests = $familyRequests;
    }

    public function registerApiCalls(ApiRoutersContainerInterface $apiRoutersContainer)
    {
        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'family', 'list'),
                \Crm\FamilyModule\Api\ListFamilyRequestsApiHandler::class,
                \Crm\UsersModule\Auth\UserTokenAuthorization::class
            )
        );
        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'family', 'activate'),
                \Crm\FamilyModule\Api\ActivateFamilyRequestApiHandler::class,
                \Crm\UsersModule\Auth\UserTokenAuthorization::class
            )
        );
    }

    public function registerEventHandlers(Emitter $emitter)
    {
        $emitter->addListener(
            \Crm\SubscriptionsModule\Events\NewSubscriptionEvent::class,
            $this->getInstance(\Crm\FamilyModule\Events\NewSubscriptionHandler::class)
        );
        $emitter->addListener(
            \Crm\SubscriptionsModule\Events\SubscriptionShortenedEvent::class,
            $this->getInstance(\Crm\FamilyModule\Events\SubscriptionShortenedHandler::class)
        );
    }

    public function registerWidgets(WidgetManagerInterface $widgetManager)
    {
        $widgetManager->registerWidget(
            'admin.user.abusive.additional',
            $this->getInstance(\Crm\FamilyModule\Components\UsersAbusiveAdditionalWidget\UsersAbusiveAdditionalWidget::class)
        );

        $widgetManager->registerWidget(
            'dashboard.simplewidget.additional',
            $this->getInstance(\Crm\FamilyModule\Components\FamilyRequestsDashboardWidget\FamilyRequestsDashboardWidget::class)
        );

        $widgetManager->registerWidget(
            'admin.user.detail.center',
            $this->getInstance(\Crm\FamilyModule\Components\MasterFamilySubscriptionInfoWidget\MasterFamilySubscriptionInfoWidget::class)
        );

        $widgetManager->registerWidget(
            'admin.user.detail.center',
            $this->getInstance(\Crm\FamilyModule\Components\SlaveFamilySubscriptionInfoWidget\SlaveFamilySubscriptionInfoWidget::class)
        );

        $widgetManager->registerWidget(
            'subscription_types_admin.show.right',
            $this->getInstance(\Crm\FamilyModule\Components\FamilySubscriptionTypeDetailsWidget\FamilySubscriptionTypeDetailsWidget::class),
            200
        );
    }

    public function registerSeeders(SeederManager $seederManager)
    {
        $seederManager->addSeeder($this->getInstance(FamilySeeder::class));
        $seederManager->addSeeder($this->getInstance(SubscriptionExtensionMethodsSeeder::class));
        $seederManager->addSeeder($this->getInstance(SubscriptionTypeNamesSeeder::class));
    }

    public function registerScenariosCriteria(ScenariosCriteriaStorage $scenariosCriteriaStorage)
    {
        $scenariosCriteriaStorage->register('subscription', 'is_family_master', $this->getInstance(IsFamilyMasterCriteria::class));
        $scenariosCriteriaStorage->register('subscription', 'is_family_slave', $this->getInstance(IsFamilySlaveCriteria::class));
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
