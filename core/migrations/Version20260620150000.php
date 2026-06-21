<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260620150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create musicbot workflow tables (musicbot_workflows, musicbot_workflow_conditions, musicbot_workflow_actions, musicbot_workflow_executions)';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $isSqlite = $platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform;

        if ($isSqlite) {
            $this->addSql('CREATE TABLE musicbot_workflows (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                customer_id INTEGER NOT NULL,
                instance_id INTEGER NOT NULL,
                name VARCHAR(120) NOT NULL,
                description CLOB DEFAULT NULL,
                trigger_type VARCHAR(40) NOT NULL,
                trigger_config CLOB NOT NULL,
                enabled BOOLEAN NOT NULL,
                last_triggered_at DATETIME DEFAULT NULL,
                execution_count INTEGER NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT fk_mw_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_mw_instance FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE
            )');
            $this->addSql('CREATE INDEX idx_musicbot_workflows_customer ON musicbot_workflows (customer_id)');
            $this->addSql('CREATE INDEX idx_musicbot_workflows_instance ON musicbot_workflows (instance_id)');
            $this->addSql('CREATE INDEX idx_musicbot_workflows_enabled ON musicbot_workflows (enabled, trigger_type)');

            $this->addSql('CREATE TABLE musicbot_workflow_conditions (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                workflow_id INTEGER NOT NULL,
                type VARCHAR(60) NOT NULL,
                value VARCHAR(255) DEFAULT NULL,
                sort_order INTEGER NOT NULL,
                CONSTRAINT fk_mwc_workflow FOREIGN KEY (workflow_id) REFERENCES musicbot_workflows (id) ON DELETE CASCADE
            )');
            $this->addSql('CREATE INDEX idx_musicbot_wf_cond_workflow ON musicbot_workflow_conditions (workflow_id)');

            $this->addSql('CREATE TABLE musicbot_workflow_actions (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                workflow_id INTEGER NOT NULL,
                type VARCHAR(60) NOT NULL,
                config CLOB NOT NULL,
                sort_order INTEGER NOT NULL,
                CONSTRAINT fk_mwa_workflow FOREIGN KEY (workflow_id) REFERENCES musicbot_workflows (id) ON DELETE CASCADE
            )');
            $this->addSql('CREATE INDEX idx_musicbot_wf_act_workflow ON musicbot_workflow_actions (workflow_id)');

            $this->addSql('CREATE TABLE musicbot_workflow_executions (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                workflow_id INTEGER NOT NULL,
                triggered_at DATETIME NOT NULL,
                completed_at DATETIME DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                trigger_context CLOB NOT NULL,
                log CLOB DEFAULT NULL,
                error CLOB DEFAULT NULL,
                duration_ms INTEGER DEFAULT NULL,
                CONSTRAINT fk_mwe_workflow FOREIGN KEY (workflow_id) REFERENCES musicbot_workflows (id) ON DELETE CASCADE
            )');
            $this->addSql('CREATE INDEX idx_musicbot_wf_exec_workflow ON musicbot_workflow_executions (workflow_id)');
            $this->addSql('CREATE INDEX idx_musicbot_wf_exec_triggered ON musicbot_workflow_executions (triggered_at)');
            $this->addSql('CREATE INDEX idx_musicbot_wf_exec_status ON musicbot_workflow_executions (status)');
        } else {
            $this->addSql('CREATE TABLE musicbot_workflows (
                id INT AUTO_INCREMENT NOT NULL,
                customer_id INT NOT NULL,
                instance_id INT NOT NULL,
                name VARCHAR(120) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                trigger_type VARCHAR(40) NOT NULL,
                trigger_config JSON NOT NULL,
                enabled TINYINT(1) NOT NULL,
                last_triggered_at DATETIME DEFAULT NULL,
                execution_count INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_musicbot_workflows_customer (customer_id),
                INDEX idx_musicbot_workflows_instance (instance_id),
                INDEX idx_musicbot_workflows_enabled (enabled, trigger_type),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE musicbot_workflows ADD CONSTRAINT fk_mw_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE musicbot_workflows ADD CONSTRAINT fk_mw_instance FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE');

            $this->addSql('CREATE TABLE musicbot_workflow_conditions (
                id INT AUTO_INCREMENT NOT NULL,
                workflow_id INT NOT NULL,
                type VARCHAR(60) NOT NULL,
                value VARCHAR(255) DEFAULT NULL,
                sort_order INT NOT NULL,
                INDEX idx_musicbot_wf_cond_workflow (workflow_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE musicbot_workflow_conditions ADD CONSTRAINT fk_mwc_workflow FOREIGN KEY (workflow_id) REFERENCES musicbot_workflows (id) ON DELETE CASCADE');

            $this->addSql('CREATE TABLE musicbot_workflow_actions (
                id INT AUTO_INCREMENT NOT NULL,
                workflow_id INT NOT NULL,
                type VARCHAR(60) NOT NULL,
                config JSON NOT NULL,
                sort_order INT NOT NULL,
                INDEX idx_musicbot_wf_act_workflow (workflow_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE musicbot_workflow_actions ADD CONSTRAINT fk_mwa_workflow FOREIGN KEY (workflow_id) REFERENCES musicbot_workflows (id) ON DELETE CASCADE');

            $this->addSql('CREATE TABLE musicbot_workflow_executions (
                id INT AUTO_INCREMENT NOT NULL,
                workflow_id INT NOT NULL,
                triggered_at DATETIME NOT NULL,
                completed_at DATETIME DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                trigger_context JSON NOT NULL,
                log LONGTEXT DEFAULT NULL,
                error LONGTEXT DEFAULT NULL,
                duration_ms INT DEFAULT NULL,
                INDEX idx_musicbot_wf_exec_workflow (workflow_id),
                INDEX idx_musicbot_wf_exec_triggered (triggered_at),
                INDEX idx_musicbot_wf_exec_status (status),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE musicbot_workflow_executions ADD CONSTRAINT fk_mwe_workflow FOREIGN KEY (workflow_id) REFERENCES musicbot_workflows (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $isSqlite = $platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform;

        if (!$isSqlite) {
            $this->addSql('ALTER TABLE musicbot_workflow_executions DROP FOREIGN KEY fk_mwe_workflow');
            $this->addSql('ALTER TABLE musicbot_workflow_actions DROP FOREIGN KEY fk_mwa_workflow');
            $this->addSql('ALTER TABLE musicbot_workflow_conditions DROP FOREIGN KEY fk_mwc_workflow');
            $this->addSql('ALTER TABLE musicbot_workflows DROP FOREIGN KEY fk_mw_customer');
            $this->addSql('ALTER TABLE musicbot_workflows DROP FOREIGN KEY fk_mw_instance');
        }

        $this->addSql('DROP TABLE IF EXISTS musicbot_workflow_executions');
        $this->addSql('DROP TABLE IF EXISTS musicbot_workflow_actions');
        $this->addSql('DROP TABLE IF EXISTS musicbot_workflow_conditions');
        $this->addSql('DROP TABLE IF EXISTS musicbot_workflows');
    }
}
