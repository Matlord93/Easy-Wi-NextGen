<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260529120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist Minecraft catalog management metadata and installed instance version state.';
    }

    public function up(Schema $schema): void
    {
        if ($this->tableExists('minecraft_versions_catalog')) {
            $this->addColumnIfMissing('minecraft_versions_catalog', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
            $this->addColumnIfMissing('minecraft_versions_catalog', 'source', "VARCHAR(16) DEFAULT 'import'");
            $this->addColumnIfMissing('minecraft_versions_catalog', 'java_version', 'VARCHAR(4) DEFAULT NULL');
            $this->addColumnIfMissing('minecraft_versions_catalog', 'notes', 'LONGTEXT DEFAULT NULL');
            $this->addColumnIfMissing('minecraft_versions_catalog', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
            $this->addColumnIfMissing('minecraft_versions_catalog', 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
            $this->addSql("UPDATE minecraft_versions_catalog SET is_active = 1 WHERE is_active IS NULL");
            $this->addSql("UPDATE minecraft_versions_catalog SET source = 'import' WHERE source IS NULL");
        }

        if ($this->tableExists('instances')) {
            $this->addColumnIfMissing('instances', 'installed_version', 'VARCHAR(120) DEFAULT NULL');
            $this->addColumnIfMissing('instances', 'installed_build_id', 'VARCHAR(64) DEFAULT NULL');
            $this->addColumnIfMissing('instances', 'installed_channel', 'VARCHAR(20) DEFAULT NULL');
            $this->addColumnIfMissing('instances', 'installed_java_version', 'VARCHAR(4) DEFAULT NULL');
            $this->addColumnIfMissing('instances', 'installed_at', 'DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void {}

    private function addColumnIfMissing(string $table, string $column, string $def): void
    {
        if ($this->columnExists($table, $column)) { return; }
        $this->addSql(sprintf('ALTER TABLE `%s` ADD `%s` %s', $table, $column, $def));
    }

    private function tableExists(string $table): bool
    {
        try { return $this->connection->createSchemaManager()->tablesExist([$table]); } catch (\Throwable) { return false; }
    }

    private function columnExists(string $table, string $column): bool
    {
        try { $columns = $this->connection->createSchemaManager()->listTableColumns($table); } catch (\Throwable) { return false; }
        return array_key_exists(strtolower($column), array_change_key_case($columns, CASE_LOWER));
    }
}
