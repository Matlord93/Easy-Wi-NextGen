<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\Backup\BackupAgent;
use App\Module\Core\Application\Backup\BackupLeaseStoreInterface;
use App\Module\Core\Application\Backup\BackupOperationHeartbeat;
use App\Module\Core\Application\Backup\BackupPlanStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:backup-plans:restore', description: 'Validate a local archive against a stored plan; use --apply to restore its configured resource.')]
final class RestoreBackupPlanCommand extends Command
{
    public function __construct(
        private readonly BackupPlanStoreInterface $store,
        private readonly BackupAgent $agent,
        private readonly ?BackupOperationHeartbeat $heartbeat = null,
        private readonly ?BackupLeaseStoreInterface $leases = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('plan', InputArgument::REQUIRED, 'Stored backup plan ID.')
            ->addArgument('archive', InputArgument::REQUIRED, 'Readable local backup archive.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write restored data to the resource configured in the plan.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $plan = $this->store->find($input->getArgument('plan'));
        } catch (\Throwable) {
            $output->writeln('<error>Unable to read backup plan. Check database migrations and the configured APP_SECRET.</error>');

            return self::FAILURE;
        }
        if ($plan === null) {
            $output->writeln('<error>Backup plan not found.</error>');

            return self::INVALID;
        }

        $scope = 'plan:'.$plan->id();
        try {
            $ttl = filter_var($plan->options()['lock_ttl_seconds'] ?? 900, FILTER_VALIDATE_INT);
            $ttlSeconds = max(30, is_int($ttl) ? $ttl : 900);
            $expires = new \DateTimeImmutable(sprintf('+%d seconds', $ttlSeconds));
            if (!$this->store->acquireLock($scope, $expires)) {
                $output->writeln('<error>Backup plan is busy. Wait for its active backup or restore to finish.</error>');

                return self::FAILURE;
            }

            $previous = $this->heartbeat?->enter(function () use ($scope, $ttlSeconds): void {
                if ($this->leases !== null && !$this->leases->renewLock($scope, new \DateTimeImmutable(sprintf('+%d seconds', $ttlSeconds)))) {
                    throw new \RuntimeException('Restore lease expired or changed ownership; operation stopped.');
                }
            });
            try {
                $this->heartbeat?->pulse(true);
                $report = $this->agent->restore($plan, $input->getArgument('archive'), !$input->getOption('apply'));
                $this->heartbeat?->pulse(true);
                $output->writeln(OutputFormatter::escape($report->message()));
                if ($report->success() && $report->dryRun()) {
                    $output->writeln('Validation only. Use --apply to restore the resource configured in this plan.');
                }

                return $report->success() ? self::SUCCESS : self::FAILURE;
            } finally {
                $this->heartbeat?->leave($previous);
                $this->store->releaseLock($scope);
            }
        } catch (\Throwable) {
            $output->writeln('<error>Restore failed. Check the backup plan, archive and database connection.</error>');

            return self::FAILURE;
        }
    }
}
