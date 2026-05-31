<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add agent jobs and node references for TS/Sinusbot nodes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agent_jobs (id VARCHAR(36) NOT NULL, node_id VARCHAR(64) NOT NULL, type VARCHAR(120) NOT NULL, payload JSON NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, log_text LONGTEXT DEFAULT NULL, error_text LONGTEXT DEFAULT NULL, retries INT NOT NULL, idempotency_key VARCHAR(64) DEFAULT NULL, result_payload JSON DEFAULT NULL, INDEX idx_agent_jobs_node_status (node_id, status), INDEX idx_agent_jobs_idempotency (idempotency_key), INDEX IDX_2789AA3C5C1662B (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE agent_jobs ADD CONSTRAINT FK_2789AA3C5C1662B FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE ts3_nodes ADD agent_id VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE ts6_nodes ADD agent_id VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE sinusbot_nodes ADD agent_id VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE ts3_nodes ADD CONSTRAINT FK_TS3_NODES_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ts6_nodes ADD CONSTRAINT FK_TS6_NODES_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sinusbot_nodes ADD CONSTRAINT FK_SINUSBOT_NODES_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ts3_nodes DROP FOREIGN KEY FK_TS3_NODES_AGENT');
        $this->addSql('ALTER TABLE ts6_nodes DROP FOREIGN KEY FK_TS6_NODES_AGENT');
        $this->addSql('ALTER TABLE sinusbot_nodes DROP FOREIGN KEY FK_SINUSBOT_NODES_AGENT');
        $this->addSql('ALTER TABLE ts3_nodes DROP agent_id');
        $this->addSql('ALTER TABLE ts6_nodes DROP agent_id');
        $this->addSql('ALTER TABLE sinusbot_nodes DROP agent_id');

        $this->addSql('DROP TABLE agent_jobs');
    }
}
