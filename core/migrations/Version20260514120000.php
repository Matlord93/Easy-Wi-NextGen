<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260514120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-port DDoS telemetry to node status records.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('ddos_statuses') || $this->columnExists('ddos_statuses', 'port_stats')) {
            return;
        }

        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql("ALTER TABLE ddos_statuses ADD COLUMN port_stats CLOB NOT NULL DEFAULT '[]'");

            return;
        }

        $this->addSql("ALTER TABLE ddos_statuses ADD port_stats JSON NOT NULL DEFAULT ('[]')");
    }

    public function down(Schema $schema): void
    {
        if (!$this->tableExists('ddos_statuses') || !$this->columnExists('ddos_statuses', 'port_stats')) {
            return;
        }

        $this->addSql('ALTER TABLE ddos_statuses DROP port_stats');
    }

    private function tableExists(string $tableName): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$tableName]);
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
