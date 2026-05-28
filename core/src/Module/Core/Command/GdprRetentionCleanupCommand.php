<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\PrivacyGdprBackgroundJobService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'gdpr:retention:cleanup', description: 'Apply GDPR retention policies to tickets, logs, and sessions. Prefer the internal Privacy & GDPR scheduler in the panel for automation.')]
final class GdprRetentionCleanupCommand extends Command
{
    public function __construct(private readonly PrivacyGdprBackgroundJobService $backgroundJobService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->backgroundJobService->applyRetentionPoliciesOnly();

        $output->writeln(sprintf('Deleted tickets: %d', $result['tickets_deleted'] ?? 0));
        $output->writeln(sprintf('Deleted ticket messages: %d', $result['ticket_messages_deleted'] ?? 0));
        $output->writeln(sprintf('Deleted important logs: %d', $result['important_logs_deleted'] ?? 0));
        $output->writeln(sprintf('Deleted non-critical logs: %d', $result['non_critical_logs_deleted'] ?? 0));
        $output->writeln(sprintf('Deleted logs (total): %d', $result['logs_deleted'] ?? 0));
        $output->writeln(sprintf('Deleted sessions: %d', $result['sessions_deleted'] ?? 0));

        return Command::SUCCESS;
    }
}
