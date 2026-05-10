<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510130000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Repair installs missing instance_metric_samples and game_template_plugins tables that were only created in the legacy Migrations.php combined migration.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->write('Skipping migration on non-MySQL/MariaDB platform.');

            return;
        }

        $this->repairInstanceMetricSamples();
        $this->repairGameTemplatePlugins();
    }

    public function down(Schema $schema): void
    {
        // Repair-only migration: do not drop runtime data on rollback.
    }

    private function repairInstanceMetricSamples(): void
    {
        if ($this->tableExists('instance_metric_samples')) {
            return;
        }

        $this->addSql('CREATE TABLE instance_metric_samples (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, cpu_percent DOUBLE PRECISION DEFAULT NULL, mem_used_bytes BIGINT DEFAULT NULL, tasks_current INT DEFAULT NULL, collected_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', error_code VARCHAR(120) DEFAULT NULL, INDEX idx_instance_metric_samples_instance_collected (instance_id, collected_at), INDEX IDX_D9719841B6BD1646 (instance_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        if ($this->tableExists('instances')) {
            $this->addSql('ALTER TABLE instance_metric_samples ADD CONSTRAINT FK_D9719841B6BD1646 FOREIGN KEY (instance_id) REFERENCES instances (id) ON DELETE CASCADE');
        }
    }

    private function repairGameTemplatePlugins(): void
    {
        if ($this->tableExists('game_template_plugins')) {
            $this->repairGameTemplatePluginsColumns();

            return;
        }

        $this->addSql('CREATE TABLE game_template_plugins (id INT AUTO_INCREMENT NOT NULL, template_id INT NOT NULL, name VARCHAR(160) NOT NULL, version VARCHAR(80) NOT NULL, checksum VARCHAR(128) NOT NULL, download_url VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, extract_subdir VARCHAR(128) DEFAULT NULL, install_mode VARCHAR(32) NOT NULL DEFAULT \'extract\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_game_template_plugins_template (template_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        if ($this->tableExists('game_templates')) {
            $this->addSql('ALTER TABLE game_template_plugins ADD CONSTRAINT FK_93368BF95DAF0FB7 FOREIGN KEY (template_id) REFERENCES game_templates (id)');
        }
    }

    private function repairGameTemplatePluginsColumns(): void
    {
        if (!$this->columnExists('game_template_plugins', 'extract_subdir')) {
            $this->addSql('ALTER TABLE game_template_plugins ADD extract_subdir VARCHAR(128) DEFAULT NULL');
        }

        if (!$this->columnExists('game_template_plugins', 'install_mode')) {
            $this->addSql("ALTER TABLE game_template_plugins ADD install_mode VARCHAR(32) NOT NULL DEFAULT 'extract'");
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
}
