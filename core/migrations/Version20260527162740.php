<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527162740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create or repair agent_jobs with an agents-compatible node_id foreign key.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('agent_jobs')) {
            $this->createAgentJobsTable();

            return;
        }

        if ($this->tableIsEmpty('agent_jobs')) {
            $this->addSql('DROP TABLE agent_jobs');
            $this->createAgentJobsTable();

            return;
        }

        $this->repairAgentJobsTable();
    }

    public function down(Schema $schema): void
    {
    }

    private function createAgentJobsTable(): void
    {
        $this->addSql('CREATE TABLE agent_jobs (id VARCHAR(36) NOT NULL, node_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, type VARCHAR(120) NOT NULL, payload JSON NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, log_text LONGTEXT DEFAULT NULL, error_text LONGTEXT DEFAULT NULL, retries INT NOT NULL, idempotency_key VARCHAR(64) DEFAULT NULL, result_payload JSON DEFAULT NULL, INDEX idx_agent_jobs_node_status (node_id, status), INDEX idx_agent_jobs_idempotency (idempotency_key), INDEX IDX_2789AA3C5C1662B (node_id), PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        if (!$this->tableExists('agents')) {
            return;
        }

        $this->addSql('ALTER TABLE agents MODIFY id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        $this->addSql('ALTER TABLE agent_jobs ADD CONSTRAINT FK_2789AA3C5C1662B FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');
    }

    private function repairAgentJobsTable(): void
    {
        foreach ($this->foreignKeysUsingNodeId('agent_jobs') as $foreignKeyName) {
            $this->addSql(sprintf('ALTER TABLE agent_jobs DROP FOREIGN KEY %s', $foreignKeyName));
        }

        $this->addSql('ALTER TABLE agent_jobs ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE agent_jobs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE agent_jobs MODIFY node_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');

        if (!$this->indexExists('agent_jobs', 'idx_agent_jobs_node_status')) {
            $this->addSql('CREATE INDEX idx_agent_jobs_node_status ON agent_jobs (node_id, status)');
        }

        if (!$this->indexExists('agent_jobs', 'IDX_2789AA3C5C1662B')) {
            $this->addSql('CREATE INDEX IDX_2789AA3C5C1662B ON agent_jobs (node_id)');
        }

        $this->addSql('ALTER TABLE agents MODIFY id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        $this->addSql('ALTER TABLE agent_jobs ADD CONSTRAINT FK_2789AA3C5C1662B FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            return false;
        }
    }

    private function tableIsEmpty(string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }

        try {
            return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table)) === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function foreignKeysUsingNodeId(string $table): array
    {
        try {
            $foreignKeys = $this->connection->createSchemaManager()->listTableForeignKeys($table);
        } catch (\Throwable) {
            return [];
        }

        $matchingNames = [];
        foreach ($foreignKeys as $foreignKey) {
            $localColumns = array_map('strtolower', $foreignKey->getLocalColumns());
            if (!in_array('node_id', $localColumns, true)) {
                continue;
            }

            $matchingNames[] = $foreignKey->getName();
        }

        return $matchingNames;
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
