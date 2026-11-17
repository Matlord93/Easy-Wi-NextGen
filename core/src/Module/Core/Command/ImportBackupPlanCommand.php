<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\Backup\BackupAdapterRegistry;
use App\Module\Core\Application\Backup\BackupPlanJsonCodec;
use App\Module\Core\Application\Backup\WritableBackupPlanStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:backup-plans:import', description: 'Import or update one encrypted, versioned backup plan from a local JSON file.')]
final class ImportBackupPlanCommand extends Command
{
    public function __construct(private readonly WritableBackupPlanStoreInterface $store, private readonly BackupPlanJsonCodec $codec, private readonly BackupAdapterRegistry $adapters)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Local version 1 plan JSON file (maximum 1 MiB).');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('file');
        if (str_contains($path, '://') || !is_file($path) || !is_readable($path)) {
            $output->writeln('<error>Provide a readable local backup plan JSON file.</error>');

            return self::INVALID;
        }
        $json = file_get_contents($path, false, null, 0, 1048577);
        if ($json === false || strlen($json) > 1048576) {
            $output->writeln('<error>Backup plan file cannot be read or exceeds 1 MiB.</error>');

            return self::INVALID;
        }
        try {
            $plan = $this->codec->decode($json);
            $this->adapters->forModule($plan->module());
        } catch (\JsonException|\InvalidArgumentException) {
            $output->writeln('<error>Invalid backup plan. Check the version 1 schema, target, module, timezone, cron and options.</error>');

            return self::INVALID;
        }
        try {
            $this->store->save($plan);
        } catch (\Throwable) {
            // Never print encrypted records, passwords or options through a DB exception.
            $output->writeln('<error>Unable to store backup plan. Check database migrations and the configured APP_SECRET.</error>');

            return self::FAILURE;
        }
        $output->writeln('Backup plan imported. Existing plans with this ID are updated.');

        return self::SUCCESS;
    }
}
