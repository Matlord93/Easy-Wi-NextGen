<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create current game_plugins catalog table and copy legacy game_template_plugins data without duplicates.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if (!$schema->hasTable('game_plugins')) {
            if ($platform instanceof SQLitePlatform) {
                $this->addSql('CREATE TABLE game_plugins (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, template_id INTEGER NOT NULL, name VARCHAR(160) NOT NULL, version VARCHAR(80) NOT NULL, checksum VARCHAR(128) NOT NULL, download_url VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, extract_subdir VARCHAR(128) DEFAULT NULL, install_mode VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
                $this->addSql('CREATE INDEX idx_game_plugins_template ON game_plugins (template_id)');
            } else {
                $this->addSql('CREATE TABLE game_plugins (id INT AUTO_INCREMENT NOT NULL, template_id INT NOT NULL, name VARCHAR(160) NOT NULL, version VARCHAR(80) NOT NULL, checksum VARCHAR(128) NOT NULL, download_url VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, extract_subdir VARCHAR(128) DEFAULT NULL, install_mode VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_game_plugins_template (template_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
                $this->addSql('ALTER TABLE game_plugins ADD CONSTRAINT FK_43DF034E5DA0FB8 FOREIGN KEY (template_id) REFERENCES game_templates (id)');
            }
        }

        if (!$schema->hasTable('game_template_plugins')) {
            return;
        }

        $legacyTable = $schema->getTable('game_template_plugins');
        foreach (['template_id', 'name', 'version', 'checksum', 'download_url', 'created_at', 'updated_at'] as $column) {
            if (!$legacyTable->hasColumn($column)) {
                $this->write(sprintf('Legacy game_template_plugins table misses %s – skipping data copy.', $column));
                return;
            }
        }

        $description = $legacyTable->hasColumn('description') ? 'legacy.description' : 'NULL';
        $extractSubdir = $legacyTable->hasColumn('extract_subdir') ? 'legacy.extract_subdir' : 'NULL';
        $installMode = $legacyTable->hasColumn('install_mode') ? 'legacy.install_mode' : "'extract'";

        $this->addSql(sprintf(
            'INSERT INTO game_plugins (template_id, name, version, checksum, download_url, description, extract_subdir, install_mode, created_at, updated_at) SELECT legacy.template_id, legacy.name, legacy.version, legacy.checksum, legacy.download_url, %s, %s, %s, legacy.created_at, legacy.updated_at FROM game_template_plugins legacy WHERE NOT EXISTS (SELECT 1 FROM game_plugins current_plugin WHERE current_plugin.template_id = legacy.template_id AND LOWER(current_plugin.name) = LOWER(legacy.name) AND current_plugin.version = legacy.version)',
            $description,
            $extractSubdir,
            $installMode,
        ));
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('game_plugins')) {
            $this->addSql('DROP TABLE game_plugins');
        }
    }
}
