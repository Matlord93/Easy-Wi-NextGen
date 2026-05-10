<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510120000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Repair installs where earlier migrations skipped due to MariaDB platform detection bug (MySQLPlatform instanceof check was false on MariaDB in DBAL 4.x).';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->write('Skipping migration on non-MySQL/MariaDB platform.');

            return;
        }

        $this->repairMetricSamples();
        $this->repairGameTemplates();
        $this->repairTeamGroups();
        $this->repairMailPolicies();
    }

    public function down(Schema $schema): void
    {
        // Repair-only migration: do not drop runtime data on rollback.
    }

    private function repairMetricSamples(): void
    {
        if ($this->tableExists('metric_samples')) {
            return;
        }

        $this->addSql('CREATE TABLE metric_samples (id INT AUTO_INCREMENT NOT NULL, agent_id VARCHAR(64) NOT NULL, recorded_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', cpu_percent DOUBLE PRECISION DEFAULT NULL, memory_percent DOUBLE PRECISION DEFAULT NULL, disk_percent DOUBLE PRECISION DEFAULT NULL, net_bytes_sent BIGINT DEFAULT NULL, net_bytes_recv BIGINT DEFAULT NULL, payload JSON DEFAULT NULL, INDEX idx_metric_samples_agent_id (agent_id), INDEX idx_metric_samples_recorded_at (recorded_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        if ($this->tableExists('agents')) {
            $this->addSql('ALTER TABLE metric_samples ADD CONSTRAINT FK_METRIC_SAMPLES_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id)');
        }
    }

    private function repairGameTemplates(): void
    {
        if ($this->tableExists('game_templates')) {
            return;
        }

        $this->addSql('CREATE TABLE game_templates (id INT AUTO_INCREMENT NOT NULL, display_name VARCHAR(120) NOT NULL, game_key VARCHAR(80) NOT NULL, description LONGTEXT DEFAULT NULL, required_ports JSON NOT NULL, start_params LONGTEXT NOT NULL, install_command LONGTEXT NOT NULL, update_command LONGTEXT NOT NULL, allowed_switch_flags JSON NOT NULL, steam_app_id INT DEFAULT NULL, sniper_profile VARCHAR(120) DEFAULT NULL, env_vars JSON NOT NULL, config_files JSON NOT NULL, plugin_paths JSON NOT NULL, fastdl_settings JSON NOT NULL, supported_os JSON NOT NULL, port_profile JSON NOT NULL, requirements JSON NOT NULL, install_resolver JSON NOT NULL, requirement_vars JSON NOT NULL, requirement_secrets JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_game_templates_key (game_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    private function repairTeamGroups(): void
    {
        if ($this->tableExists('team_groups')) {
            return;
        }

        $this->addSql('CREATE TABLE team_groups (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(140) NOT NULL, game VARCHAR(140) NOT NULL, slug VARCHAR(180) NOT NULL, image_path VARCHAR(255) DEFAULT NULL, sort_order INT NOT NULL, site_id INT NOT NULL, INDEX IDX_86767EA9F6BD1646 (site_id), INDEX idx_team_groups_site_sort (site_id, sort_order), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        if ($this->tableExists('sites') && $this->columnTypesMatch('team_groups', 'site_id', 'sites', 'id')) {
            $this->addSql('ALTER TABLE team_groups ADD CONSTRAINT FK_86767EA9F6BD1646 FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
        }
    }

    private function repairMailPolicies(): void
    {
        if (!$this->tableExists('mail_policies')) {
            return;
        }

        if (!$this->columnExists('mail_policies', 'smtp_enabled')) {
            $this->addSql('ALTER TABLE mail_policies ADD smtp_enabled TINYINT(1) DEFAULT 1 NOT NULL');
        }

        if (!$this->columnExists('mail_policies', 'abuse_policy_enabled')) {
            $this->addSql('ALTER TABLE mail_policies ADD abuse_policy_enabled TINYINT(1) DEFAULT 1 NOT NULL');
        }
    }

    private function tableExists(string $tableName): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$tableName],
        ) > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        ) > 0;
    }

    private function columnTypesMatch(string $tableA, string $colA, string $tableB, string $colB): bool
    {
        $typeA = $this->connection->fetchOne(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tableA, $colA],
        );
        $typeB = $this->connection->fetchOne(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tableB, $colB],
        );

        return $typeA !== false && $typeA === $typeB;
    }
}
