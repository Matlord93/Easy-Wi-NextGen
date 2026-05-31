<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260510110000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Ensure interrupted installers have metrics and port range tables without relying on schema sync foreign keys.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->write('Skipping migration on non-MySQL platform.');

            return;
        }

        if (!$this->tableExists('metric_samples')) {
            $this->addSql('CREATE TABLE metric_samples (id INT AUTO_INCREMENT NOT NULL, agent_id VARCHAR(64) NOT NULL, recorded_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', cpu_percent DOUBLE PRECISION DEFAULT NULL, memory_percent DOUBLE PRECISION DEFAULT NULL, disk_percent DOUBLE PRECISION DEFAULT NULL, net_bytes_sent BIGINT DEFAULT NULL, net_bytes_recv BIGINT DEFAULT NULL, payload JSON DEFAULT NULL, INDEX idx_metric_samples_agent_id (agent_id), INDEX idx_metric_samples_recorded_at (recorded_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$this->tableExists('port_ranges')) {
            $this->addSql('CREATE TABLE port_ranges (id INT AUTO_INCREMENT NOT NULL, node_id VARCHAR(64) NOT NULL, purpose VARCHAR(120) NOT NULL, protocol VARCHAR(8) NOT NULL, start_port INT NOT NULL, end_port INT NOT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_port_ranges_node_id (node_id), INDEX idx_port_ranges_protocol (protocol), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        // Repair-only migration: do not drop runtime data on rollback.
    }

    private function tableExists(string $tableName): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$tableName],
        ) > 0;
    }
}
