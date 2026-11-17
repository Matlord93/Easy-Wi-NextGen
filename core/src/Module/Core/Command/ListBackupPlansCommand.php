<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\Backup\BackupPlanStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:backup-plans:list', description: 'List backup plan IDs, modules and schedules without configuration or secrets.')]
final class ListBackupPlansCommand extends Command
{
    public function __construct(private readonly BackupPlanStoreInterface $store)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            foreach ($this->store->all() as $plan) {
                $output->writeln(OutputFormatter::escape(json_encode(['id' => $plan->id(), 'module' => $plan->module(), 'cron' => $plan->cronExpression(), 'timezone' => $plan->timeZone()], JSON_THROW_ON_ERROR)));
            }
        } catch (\Throwable) {
            $output->writeln('<error>Unable to read backup plans. Check database migrations and the configured APP_SECRET.</error>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
