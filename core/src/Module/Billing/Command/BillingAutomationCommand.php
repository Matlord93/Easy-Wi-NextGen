<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:billing:automation',
    description: 'Sync invoice statuses and apply dunning reminders.',
)]
final class BillingAutomationCommand extends Command
{
    public function __construct(private readonly \App\Module\Core\Application\Billing\BillingAutomationService $automation)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->automation->run();
        $output->writeln(sprintf('Processed %d invoices (%d reminders).', $result['invoices'], $result['reminders']));

        return Command::SUCCESS;
    }
}
