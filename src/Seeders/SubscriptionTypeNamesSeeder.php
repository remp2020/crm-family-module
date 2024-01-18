<?php

namespace Crm\FamilyModule\Seeders;

use Crm\ApplicationModule\Seeders\ISeeder;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeNamesRepository;
use Symfony\Component\Console\Output\OutputInterface;

class SubscriptionTypeNamesSeeder implements ISeeder
{
    private $subscriptionTypeNamesRepository;

    public function __construct(SubscriptionTypeNamesRepository $subscriptionTypeNamesRepository)
    {
        $this->subscriptionTypeNamesRepository = $subscriptionTypeNamesRepository;
    }

    public function seed(OutputInterface $output)
    {
        $types = [
            900 => 'family',
        ];

        foreach ($types as $sorting => $name) {
            if (!$this->subscriptionTypeNamesRepository->exists($name)) {
                $this->subscriptionTypeNamesRepository->add($name, $sorting);
                $output->writeln("  <comment>* subscription type name <info>{$name}</info> created</comment>");
            } else {
                $output->writeln("  * subscription type method <info>{$name}</info> exists");
            }
        }
    }
}
