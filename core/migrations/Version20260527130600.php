<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260527130600 extends AbstractMigration
{
    public function getDescription(): string { return 'Add installed_version column to node tables when missing.'; }

    public function up(Schema $schema): void
    {
        foreach (['ts3_nodes', 'ts6_nodes', 'sinusbot_nodes'] as $table) {
            if (!$this->tableExists($table)) { continue; }
            $this->addColumnIfMissing($table, 'installed_version', 'VARCHAR(120) DEFAULT NULL');
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
        try {
            return $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $columns = $this->connection->createSchemaManager()->listTableColumns($table);
        } catch (\Throwable) {
            return false;
        }

        return array_key_exists(strtolower($column), array_change_key_case($columns, CASE_LOWER));
    }
}
