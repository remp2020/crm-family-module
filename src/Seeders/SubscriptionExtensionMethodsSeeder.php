<?php

namespace Crm\FamilyModule\Seeders;

use Crm\ApplicationModule\Seeders\ISeeder;
use Crm\FamilyModule\Models\Extension\ExtendFamilyExtension;
use Crm\SubscriptionsModule\Repositories\SubscriptionExtensionMethodsRepository;
use Symfony\Component\Console\Output\OutputInterface;

class SubscriptionExtensionMethodsSeeder implements ISeeder
{
    private $subscriptionExtensionMethodsRepository;

    public function __construct(SubscriptionExtensionMethodsRepository $subscriptionExtensionMethodsRepository)
    {
        $this->subscriptionExtensionMethodsRepository = $subscriptionExtensionMethodsRepository;
    }

    public function seed(OutputInterface $output)
    {
        $method = ExtendFamilyExtension::METHOD_CODE;
        if (!$this->subscriptionExtensionMethodsRepository->exists($method)) {
            $this->subscriptionExtensionMethodsRepository->add(
                $method,
                ExtendFamilyExtension::METHOD_NAME,
                'Put new subscription after newest family subscription or start now',
                400,
            );
            $output->writeln("  <comment>* subscription extension method <info>{$method}</info> created</comment>");
        } else {
            $output->writeln("  * subscription extension method <info>{$method}</info> exists");
        }
    }
}
