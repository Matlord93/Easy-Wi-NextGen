<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260510140000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Repair FK_PORT_POOLS_NODE to use Doctrine-generated name FK_44CBF4C6460D9FD7 so schema sync does not duplicate the constraint.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->write('Skipping migration on non-MySQL/MariaDB platform.');

            return;
        }

        if (!$this->tableExists('port_pools') || !$this->tableExists('agents')) {
            return;
        }

        $hasOld = $this->hasForeignKey('port_pools', 'FK_PORT_POOLS_NODE');
        $hasNew = $this->hasForeignKey('port_pools', 'FK_44CBF4C6460D9FD7');

        if ($hasNew) {
            // Already using Doctrine-expected name; drop the legacy name if still present.
            if ($hasOld) {
                $this->addSql('ALTER TABLE port_pools DROP FOREIGN KEY FK_PORT_POOLS_NODE');
            }

            return;
        }

        if ($hasOld) {
            $this->addSql('ALTER TABLE port_pools DROP FOREIGN KEY FK_PORT_POOLS_NODE');
        }

        $this->addSql('ALTER TABLE port_pools ADD CONSTRAINT FK_44CBF4C6460D9FD7 FOREIGN KEY (node_id) REFERENCES agents (id)');
    }

    public function down(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        if (!$this->tableExists('port_pools') || !$this->tableExists('agents')) {
            return;
        }

        if (!$this->hasForeignKey('port_pools', 'FK_44CBF4C6460D9FD7')) {
            return;
        }

        $this->addSql('ALTER TABLE port_pools DROP FOREIGN KEY FK_44CBF4C6460D9FD7');
        $this->addSql('ALTER TABLE port_pools ADD CONSTRAINT FK_PORT_POOLS_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
    }

    private function tableExists(string $tableName): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$tableName],
        ) > 0;
    }

    private function hasForeignKey(string $table, string $foreignKey): bool
    {
        $database = (string) $this->connection->fetchOne('SELECT DATABASE()');

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = \'FOREIGN KEY\'',
            [$database, $table, $foreignKey],
        ) > 0;
    }
}
