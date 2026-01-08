<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250301090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add instance update policy and build metadata tracking.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('agents')) {
            $this->addSql('CREATE TABLE agents (id VARCHAR(64) NOT NULL, name VARCHAR(120) DEFAULT NULL, secret_payload JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_heartbeat_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_seen_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_heartbeat_ip VARCHAR(45) DEFAULT NULL, last_heartbeat_version VARCHAR(40) DEFAULT NULL, last_heartbeat_stats JSON DEFAULT NULL, metadata JSON DEFAULT NULL, roles JSON NOT NULL, status VARCHAR(20) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('instances')) {
            $this->addSql('CREATE TABLE instances (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, template_id INT NOT NULL, node_id VARCHAR(64) NOT NULL, cpu_limit INT NOT NULL, ram_limit INT NOT NULL, disk_limit INT NOT NULL, port_block_id VARCHAR(64) DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_instances_customer_id (customer_id), INDEX idx_instances_template_id (template_id), INDEX idx_instances_node_id (node_id), INDEX idx_instances_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE instances ADD CONSTRAINT FK_INSTANCES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
            $this->addSql('ALTER TABLE instances ADD CONSTRAINT FK_INSTANCES_TEMPLATE FOREIGN KEY (template_id) REFERENCES game_templates (id)');
            $this->addSql('ALTER TABLE instances ADD CONSTRAINT FK_INSTANCES_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
        }

        $this->addSql('ALTER TABLE instances ADD update_policy VARCHAR(16) NOT NULL DEFAULT \'manual\', ADD locked_build_id VARCHAR(64) DEFAULT NULL, ADD locked_version VARCHAR(120) DEFAULT NULL, ADD current_build_id VARCHAR(64) DEFAULT NULL, ADD current_version VARCHAR(120) DEFAULT NULL, ADD previous_build_id VARCHAR(64) DEFAULT NULL, ADD previous_version VARCHAR(120) DEFAULT NULL, ADD last_update_queued_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances DROP update_policy, DROP locked_build_id, DROP locked_version, DROP current_build_id, DROP current_version, DROP previous_build_id, DROP previous_version, DROP last_update_queued_at');
    }
}
