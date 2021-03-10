<?php

namespace Crm\FamilyModule\Tests;

use Crm\ApplicationModule\Cache\CacheRepository;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\FamilyModule\Seeders\FamilySeeder;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentItemsRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Repository\ContentAccessRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionExtensionMethodsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionLengthMethodsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesMetaRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Seeders\ContentAccessSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\UsersModule\Repository\LoginAttemptsRepository;
use Crm\UsersModule\Repository\UsersRepository;

abstract class BaseTestCase extends DatabaseTestCase
{
    use SeedFamilySubscriptionTypesTrait;

    /** @var SubscriptionTypeBuilder */
    protected $subscriptionTypeBuilder;

    /** @var SubscriptionTypesMetaRepository */
    private $subscriptionTypesMetaRepository;

    /** @var FamilySubscriptionTypesRepository */
    protected $familySubscriptionTypesRepository;

    protected function requiredRepositories(): array
    {
        return [
            UsersRepository::class,
            LoginAttemptsRepository::class,
            // To work with subscriptions, we need all these tables
            SubscriptionsRepository::class,
            SubscriptionMetaRepository::class,
            SubscriptionTypesRepository::class,
            SubscriptionTypesMetaRepository::class,
            SubscriptionExtensionMethodsRepository::class,
            SubscriptionLengthMethodsRepository::class,
            // And content access for access types of subscription types
            ContentAccessRepository::class,
            // Payments + recurrent payments
            PaymentGatewaysRepository::class,
            PaymentItemsRepository::class,
            PaymentsRepository::class,
            PaymentMetaRepository::class,
            RecurrentPaymentsRepository::class,
            // Family subscriptions
            FamilyRequestsRepository::class,
            FamilySubscriptionTypesRepository::class,
            // Cache
            CacheRepository::class
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
            ContentAccessSeeder::class,
            SubscriptionExtensionMethodsSeeder::class,
            \Crm\FamilyModule\Seeders\SubscriptionExtensionMethodsSeeder::class,
            SubscriptionLengthMethodSeeder::class,
            SubscriptionTypeNamesSeeder::class,
            FamilySeeder::class,
        ];
    }

    protected function setUp(): void
    {
        $this->refreshContainer();
        parent::setUp();

        $this->subscriptionTypeBuilder = $this->inject(SubscriptionTypeBuilder::class);
        $this->subscriptionTypesMetaRepository = $this->inject(SubscriptionTypesMetaRepository::class);
        $this->familySubscriptionTypesRepository = $this->inject(FamilySubscriptionTypesRepository::class);
    }
}
