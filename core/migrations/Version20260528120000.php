<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260528120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes for reduced database logging retention and scheduler status lookups.';
    }

    public function up(Schema $schema): void
    {
        if ($this->tableExists('audit_logs') && !$this->indexExists('audit_logs', 'idx_audit_logs_action_created_at')) {
            $this->addSql('CREATE INDEX idx_audit_logs_action_created_at ON audit_logs (action, created_at)');
        }

        if ($this->tableExists('scheduled_task_runs') && !$this->indexExists('scheduled_task_runs', 'idx_scheduled_task_runs_status_started_at')) {
            $this->addSql('CREATE INDEX idx_scheduled_task_runs_status_started_at ON scheduled_task_runs (status, started_at)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->tableExists('audit_logs') && $this->indexExists('audit_logs', 'idx_audit_logs_action_created_at')) {
            $this->addSql('DROP INDEX idx_audit_logs_action_created_at ON audit_logs');
        }

        if ($this->tableExists('scheduled_task_runs') && $this->indexExists('scheduled_task_runs', 'idx_scheduled_task_runs_status_started_at')) {
            $this->addSql('DROP INDEX idx_scheduled_task_runs_status_started_at ON scheduled_task_runs');
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            return false;
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $indexes = $this->connection->createSchemaManager()->listTableIndexes($table);

            return array_key_exists(strtolower($indexName), array_change_key_case($indexes, CASE_LOWER));
        } catch (\Throwable) {
            return false;
        }
    }
}
