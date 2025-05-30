<?php

namespace Crm\FamilyModule\Seeders;

use Crm\ApplicationModule\Repositories\SnippetsRepository;
use Crm\ApplicationModule\Seeders\ISeeder;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeNamesRepository;
use Symfony\Component\Console\Output\OutputInterface;

class FamilySeeder implements ISeeder
{
    private $subscriptionTypeNamesRepository;

    private $snippetsRepository;

    public function __construct(
        SubscriptionTypeNamesRepository $subscriptionTypeNamesRepository,
        SnippetsRepository $snippetsRepository,
    ) {
        $this->subscriptionTypeNamesRepository = $subscriptionTypeNamesRepository;
        $this->snippetsRepository = $snippetsRepository;
    }

    public function seed(OutputInterface $output)
    {
        $sorting = 1100;
        foreach (glob(__DIR__ . '/snippets/*.html') as $filename) {
            $info = pathinfo($filename);
            $key = $info['filename'];

            $snippet = $this->snippetsRepository->findBy('identifier', $key);
            $value = file_get_contents($filename);

            if (!$snippet) {
                $this->snippetsRepository->add($key, $key, $value, $sorting++, true, true);
                $output->writeln('  <comment>* snippet <info>' . $key . '</info> created</comment>');
            } elseif ($snippet->has_default_value && $snippet->html !== $value) {
                $this->snippetsRepository->update($snippet, ['html' => $value, 'has_default_value' => true]);
                $output->writeln('  <comment>* snippet <info>' . $key . '</info> updated</comment>');
            } else {
                $output->writeln('  * snippet <info>' . $key . '</info> exists');
            }
        }

        $name = 'family';
        $sorting = 900;
        if (!$this->subscriptionTypeNamesRepository->exists($name)) {
            $this->subscriptionTypeNamesRepository->add($name, $sorting);
            $output->writeln("  <comment>* subscription type name <info>{$name}</info> created</comment>");
        } else {
            $output->writeln("  * subscription type method <info>{$name}</info> exists");
        }
    }
}
