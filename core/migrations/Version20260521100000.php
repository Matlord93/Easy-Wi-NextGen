<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260521100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track whether a gameserver instance uses shared storage.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('instances') || $this->columnExists('instances', 'shared_storage_enabled')) {
            return;
        }

        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE instances ADD shared_storage_enabled BOOLEAN NOT NULL DEFAULT FALSE');

            return;
        }

        $this->addSql('ALTER TABLE instances ADD shared_storage_enabled TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        if (!$this->tableExists('instances') || !$this->columnExists('instances', 'shared_storage_enabled')) {
            return;
        }

        $this->addSql('ALTER TABLE instances DROP shared_storage_enabled');
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
