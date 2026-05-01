<?php

declare(strict_types=1);

namespace App\Module\EasyWiMigration\Command;

use App\Module\EasyWiMigration\Application\EasyWiConnectionConfig;
use App\Module\EasyWiMigration\Application\EasyWiMigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'easywi:import',
    description: 'Import data from a legacy Easy-Wi 3.x database into Easy-Wi NextGen.',
)]
final class EasyWiImportCommand extends Command
{
    private const ALL_ENTITIES = ['users', 'gameservers', 'voice', 'webspaces', 'domains', 'mailboxes', 'invoices'];

    public function __construct(
        private readonly EasyWiMigrationService $migrationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Legacy DB host', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Legacy DB port', 3306)
            ->addOption('dbname', null, InputOption::VALUE_REQUIRED, 'Legacy DB name')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Legacy DB user', 'root')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Legacy DB password', '')
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Legacy table prefix', 'easywi_')
            ->addOption('entities', null, InputOption::VALUE_REQUIRED, 'Comma-separated entities to import (default: all)', implode(',', self::ALL_ENTITIES))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and count without writing to DB')
            ->addOption('probe', null, InputOption::VALUE_NONE, 'Show table row counts in the source DB and exit')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dbname = (string) $input->getOption('dbname');
        if ($dbname === '') {
            $io->error('--dbname is required.');
            return Command::FAILURE;
        }

        $config = new EasyWiConnectionConfig(
            host: (string) $input->getOption('host'),
            port: (int) $input->getOption('port'),
            dbName: $dbname,
            username: (string) $input->getOption('user'),
            password: (string) $input->getOption('password'),
            tablePrefix: (string) $input->getOption('prefix'),
        );

        // ── Probe mode ──
        if ($input->getOption('probe')) {
            $io->section('Easy-Wi Source Database — Table Counts');
            try {
                $counts = $this->migrationService->probe($config);
            } catch (\Throwable $e) {
                $io->error('Connection failed: ' . $e->getMessage());
                return Command::FAILURE;
            }
            $rows = [];
            foreach ($counts as $table => $count) {
                $rows[] = [$table, number_format($count)];
            }
            $io->table(['Table', 'Rows'], $rows);
            return Command::SUCCESS;
        }

        $rawEntities = (string) $input->getOption('entities');
        $entities = array_filter(array_map('trim', explode(',', $rawEntities)));
        $invalid = array_diff($entities, self::ALL_ENTITIES);
        if ($invalid !== []) {
            $io->error(sprintf('Unknown entities: %s. Valid: %s', implode(', ', $invalid), implode(', ', self::ALL_ENTITIES)));
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        if ($dryRun) {
            $io->note('DRY-RUN mode — no data will be written.');
        }

        $io->section(sprintf('Importing: %s', implode(', ', $entities)));

        try {
            $results = $this->migrationService->run($config, $entities, $dryRun);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $rows = [];
        $hasErrors = false;
        foreach ($results as $result) {
            $rows[] = [$result->entity, $result->imported, $result->skipped, $result->failed, $result->total()];
            if ($result->failed > 0) {
                $hasErrors = true;
                foreach ($result->errors as $err) {
                    $io->warning($err);
                }
            }
        }

        $io->table(['Entity', 'Imported', 'Skipped', 'Failed', 'Total'], $rows);

        if ($dryRun) {
            $io->note('DRY-RUN complete — no changes persisted.');
        } else {
            $io->success('Migration complete.');
        }

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }
}
