<?php

declare(strict_types=1);

namespace App\Module\EasyWiMigration\Application;

use Psr\Log\LoggerInterface;

/**
 * Orchestrates a full or partial Easy-Wi 3.x → NextGen migration run.
 *
 * Each entity type can be selected independently so migrations can be
 * performed in stages (users first, then servers, etc.) with the ability
 * to rerun safely — already-existing records are skipped, not duplicated.
 */
final class EasyWiMigrationService
{
    public function __construct(
        private readonly EasyWiUserImporter $userImporter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Run a migration with the given connection config.
     *
     * @param list<string> $entities  Subset of: users, gameservers, voice, webspaces, domains, mailboxes, invoices
     * @return list<EasyWiMigrationResult>
     */
    public function run(EasyWiConnectionConfig $config, array $entities, bool $dryRun = false): array
    {
        try {
            $reader = new EasyWiSourceReader($config);
        } catch (\Throwable $e) {
            $this->logger->error('easywi.migration.connect_failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Cannot connect to Easy-Wi source database: ' . $e->getMessage(), 0, $e);
        }

        $results = [];

        foreach ($entities as $entity) {
            $this->logger->info('easywi.migration.entity_start', ['entity' => $entity, 'dry_run' => $dryRun]);
            $result = match ($entity) {
                'users' => $this->userImporter->import($reader, $dryRun),
                default => new EasyWiMigrationResult($entity, 0, 0, 0, ["Import for '{$entity}' is not yet implemented."]),
            };
            $results[] = $result;
            $this->logger->info('easywi.migration.entity_done', [
                'entity' => $entity,
                'imported' => $result->imported,
                'skipped' => $result->skipped,
                'failed' => $result->failed,
            ]);
        }

        return $results;
    }

    /**
     * @return array<string, int>  Table name => row count
     */
    public function probe(EasyWiConnectionConfig $config): array
    {
        $reader = new EasyWiSourceReader($config);
        $tables = ['users', 'gameserver', 'voice', 'webspace', 'domains', 'mailboxes', 'invoices'];
        $counts = [];
        foreach ($tables as $table) {
            if ($reader->tableExists($table)) {
                $counts[$table] = $reader->countTable($table);
            }
        }
        return $counts;
    }
}
