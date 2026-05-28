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
        $nodeIdColumnDefinition = $this->agentIdColumnDefinitionSql();

        if (!$this->tableExists('agent_jobs')) {
            $this->addSql(sprintf(
                'CREATE TABLE agent_jobs (id VARCHAR(36) NOT NULL, node_id %s NOT NULL, type VARCHAR(120) NOT NULL, payload JSON NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, log_text LONGTEXT DEFAULT NULL, error_text LONGTEXT DEFAULT NULL, retries INT NOT NULL, idempotency_key VARCHAR(64) DEFAULT NULL, result_payload JSON DEFAULT NULL, INDEX idx_agent_jobs_node_status (node_id, status), INDEX idx_agent_jobs_idempotency (idempotency_key), INDEX IDX_2789AA3C5C1662B (node_id), PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                $nodeIdColumnDefinition,
            ));
            $this->addSql('ALTER TABLE agent_jobs ADD CONSTRAINT FK_2789AA3C5C1662B FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');

            return;
        }

        foreach ($this->foreignKeysReferencingAgentsNodeId('agent_jobs') as $foreignKeyName) {
            $this->addSql(sprintf('ALTER TABLE agent_jobs DROP FOREIGN KEY %s', $foreignKeyName));
        }

        $this->addSql('ALTER TABLE agent_jobs ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE agent_jobs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->addSql(sprintf('ALTER TABLE agent_jobs MODIFY node_id %s NOT NULL', $nodeIdColumnDefinition));

        if (!$this->indexExists('agent_jobs', 'idx_agent_jobs_node_status')) {
            $this->addSql('CREATE INDEX idx_agent_jobs_node_status ON agent_jobs (node_id, status)');
        }

        if (!$this->indexExists('agent_jobs', 'IDX_2789AA3C5C1662B')) {
            $this->addSql('CREATE INDEX IDX_2789AA3C5C1662B ON agent_jobs (node_id)');
        }

        $this->addSql('ALTER TABLE agent_jobs ADD CONSTRAINT FK_2789AA3C5C1662B FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
    }

    private function agentIdColumnDefinitionSql(): string
    {
        try {
            $agentIdColumn = $this->connection->fetchAssociative(
                'SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['agents', 'id'],
            );
        } catch (\Throwable) {
            $agentIdColumn = false;
        }

        $characterSet = is_array($agentIdColumn) && is_string($agentIdColumn['CHARACTER_SET_NAME'] ?? null)
            ? $agentIdColumn['CHARACTER_SET_NAME']
            : 'utf8mb4';
        $collation = is_array($agentIdColumn) && is_string($agentIdColumn['COLLATION_NAME'] ?? null)
            ? $agentIdColumn['COLLATION_NAME']
            : 'utf8mb4_unicode_ci';

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $characterSet) || !preg_match('/^[a-zA-Z0-9_]+$/', $collation)) {
            $characterSet = 'utf8mb4';
            $collation = 'utf8mb4_unicode_ci';
        }

        return sprintf('VARCHAR(64) CHARACTER SET %s COLLATE %s', $characterSet, $collation);
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function foreignKeysReferencingAgentsNodeId(string $table): array
    {
        try {
            $foreignKeys = $this->connection->createSchemaManager()->listTableForeignKeys($table);
        } catch (\Throwable) {
            return [];
        }

        $matchingNames = [];
        foreach ($foreignKeys as $foreignKey) {
            $localColumns = array_map('strtolower', $foreignKey->getLocalColumns());
            if ($foreignKey->getForeignTableName() !== 'agents' || !in_array('node_id', $localColumns, true)) {
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
