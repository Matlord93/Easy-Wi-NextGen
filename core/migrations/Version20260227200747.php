<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260227200747 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Hosting Panel node/agent tables with guards for pre-existing deployments.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('hp_node')) {
            $this->addSql("CREATE TABLE hp_node (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, fqdn VARCHAR(255) NOT NULL, ip_address VARCHAR(45) NOT NULL, online TINYINT(1) NOT NULL, last_seen_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_HP_NODE_NAME (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        }

        if (!$schema->hasTable('hp_agent')) {
            $this->addSql("CREATE TABLE hp_agent (id INT AUTO_INCREMENT NOT NULL, node_id INT NOT NULL, agent_uuid VARCHAR(64) NOT NULL, version VARCHAR(32) NOT NULL, os VARCHAR(32) NOT NULL, capabilities JSON NOT NULL, token_hash VARCHAR(128) NOT NULL, last_seen_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_HP_AGENT_NODE_ID (node_id), UNIQUE INDEX UNIQ_HP_AGENT_UUID (agent_uuid), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE hp_agent ADD CONSTRAINT FK_HP_AGENT_NODE FOREIGN KEY (node_id) REFERENCES hp_node (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('hp_agent')) {
            $this->addSql('DROP TABLE hp_agent');
        }

        if ($schema->hasTable('hp_node')) {
            $this->addSql('DROP TABLE hp_node');
        }
    }
}
