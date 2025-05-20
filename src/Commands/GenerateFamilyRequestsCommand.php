<?php

namespace Crm\FamilyModule\Commands;

use Crm\ApplicationModule\Commands\DecoratedCommandTrait;
use Crm\FamilyModule\Models\FamilyRequests;
use Crm\FamilyModule\Models\MissingFamilySubscriptionTypeException;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateFamilyRequestsCommand extends Command
{
    use DecoratedCommandTrait;

    public function __construct(
        private FamilyRequests $familyRequests,
        private SubscriptionsRepository $subscriptionsRepository,
        private FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('family:generate_family_requests')
            ->setDescription('Generates family requests. Useful when requests are missing for some reason.')
            ->addOption(
                'subscription_id',
                null,
                InputOption::VALUE_REQUIRED,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $subscriptionId = $input->getOption('subscription_id');
        if (!$subscriptionId) {
            $this->error('Missing parameter --subscription_id');
            return Command::INVALID;
        }

        $subscriptionRow = $this->subscriptionsRepository->find($subscriptionId);
        if (!$subscriptionRow) {
            $this->error('Subscription #' . $subscriptionId . ' not found.');
            return Command::FAILURE;
        }

        $familySubscriptionType = $this->familySubscriptionTypesRepository
            ->findByMasterSubscriptionType($subscriptionRow->subscription_type);

        if (!$familySubscriptionType) {
            $this->error('Subscription #' . $subscriptionId . ' is not a family subscription.');
            return Command::FAILURE;
        }

        if (!$familySubscriptionType->slave_subscription_type_id) {
            $confirmed = $this->confirm("Subscription #{$subscriptionId} is master subscription but has no associated " .
                "slave subscription type.\nIn such case, there is no check that slave subscription requests already exist.\n" .
                "Do you want to continue?");
            if (!$confirmed) {
                $this->line('Command cancelled');
                return Command::FAILURE;
            }
        }

        try {
            $newRequests = $this->familyRequests->createFromSubscription($subscriptionRow);
            if ($newRequests) {
                $this->line(' *  New <info>' . count($newRequests) . '</info> family request(s) created.');
            } else {
                $this->line(' *  No family requests created.');
            }
        } catch (MissingFamilySubscriptionTypeException $e) {
            $this->error('Subscription #' . $subscriptionId . ' is not a family subscription.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
