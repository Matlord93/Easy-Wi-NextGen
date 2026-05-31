<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260526110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add encrypted one-time credential storage to databases.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('databases') || $this->columnExists('databases', 'encrypted_one_time_credential')) {
            return;
        }

        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE databases ADD encrypted_one_time_credential JSON DEFAULT NULL');

            return;
        }

        $this->addSql('ALTER TABLE `databases` ADD `encrypted_one_time_credential` JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$this->tableExists('databases') || !$this->columnExists('databases', 'encrypted_one_time_credential')) {
            return;
        }

        $this->addSql('ALTER TABLE `databases` DROP `encrypted_one_time_credential`');
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
