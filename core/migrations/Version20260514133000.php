<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260514133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow tickets to be assigned to an admin user.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('tickets') || $this->columnExists('tickets', 'assigned_to_id')) {
            return;
        }

        $this->addSql('ALTER TABLE tickets ADD assigned_to_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_tickets_assigned_to_id ON tickets (assigned_to_id)');

        if (!$this->isSqlite()) {
            $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_TICKETS_ASSIGNED_TO FOREIGN KEY (assigned_to_id) REFERENCES users (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$this->tableExists('tickets') || !$this->columnExists('tickets', 'assigned_to_id')) {
            return;
        }

        if (!$this->isSqlite() && $this->foreignKeyExists('tickets', 'FK_TICKETS_ASSIGNED_TO')) {
            $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_TICKETS_ASSIGNED_TO');
        }

        if ($this->indexExists('tickets', 'idx_tickets_assigned_to_id')) {
            $this->addSql($this->isSqlite() ? 'DROP INDEX idx_tickets_assigned_to_id' : 'DROP INDEX idx_tickets_assigned_to_id ON tickets');
        }

        $this->addSql('ALTER TABLE tickets DROP assigned_to_id');
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

    private function indexExists(string $table, string $index): bool
    {
        try {
            $indexes = $this->connection->createSchemaManager()->listTableIndexes($table);
        } catch (\Throwable) {
            return false;
        }

        return array_key_exists(strtolower($index), array_change_key_case($indexes, CASE_LOWER));
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        try {
            $foreignKeys = $this->connection->createSchemaManager()->listTableForeignKeys($table);
        } catch (\Throwable) {
            return false;
        }

        foreach ($foreignKeys as $key) {
            if (strcasecmp($key->getName(), $foreignKey) === 0) {
                return true;
            }
        }

        return false;
    }

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }
}
