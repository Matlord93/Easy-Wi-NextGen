<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527162740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create agent_jobs with utf8mb4_unicode_ci collation and FK to agents; repair partial tables.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('agent_jobs')) {
            $count = (int) $this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s', $this->connection->quoteIdentifier('agent_jobs'))
            );

            if ($count === 0) {
                $this->addSql('DROP TABLE agent_jobs');
            } else {
                $this->addSql('ALTER TABLE agent_jobs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $this->addSql('ALTER TABLE agent_jobs MODIFY node_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');

                if (!$schema->hasTable('agents')) {
                    return;
                }

                $this->addSql('ALTER TABLE agents MODIFY id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
                $this->addSql('ALTER TABLE agent_jobs ADD CONSTRAINT FK_2789AA3C5C1662B FOREIGN KEY (node_id) REFERENCES agents (id)');
                return;
            }
        }

        if (!$schema->hasTable('agents')) {
            $this->addSql('CREATE TABLE agent_jobs (id VARCHAR(36) NOT NULL, type VARCHAR(120) NOT NULL, payload JSON NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, log_text LONGTEXT DEFAULT NULL, error_text LONGTEXT DEFAULT NULL, retries INT NOT NULL, idempotency_key VARCHAR(64) DEFAULT NULL, result_payload JSON DEFAULT NULL, node_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, INDEX IDX_2789AA3C5C1662B (node_id), INDEX idx_agent_jobs_node_status (node_id, status), INDEX idx_agent_jobs_idempotency (idempotency_key), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            return;
        }

        $this->addSql('ALTER TABLE agents MODIFY id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        $this->addSql('CREATE TABLE agent_jobs (id VARCHAR(36) NOT NULL, type VARCHAR(120) NOT NULL, payload JSON NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, log_text LONGTEXT DEFAULT NULL, error_text LONGTEXT DEFAULT NULL, retries INT NOT NULL, idempotency_key VARCHAR(64) DEFAULT NULL, result_payload JSON DEFAULT NULL, node_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, INDEX IDX_2789AA3C5C1662B (node_id), INDEX idx_agent_jobs_node_status (node_id, status), INDEX idx_agent_jobs_idempotency (idempotency_key), FOREIGN KEY (node_id) REFERENCES agents (id), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS agent_jobs');
    }
}
