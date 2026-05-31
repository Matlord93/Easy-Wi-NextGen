<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20250318100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional customer assignment to Sinusbot nodes.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('sinusbot_nodes')) {
            return;
        }

        $table = $schema->getTable('sinusbot_nodes');
        if ($table->hasColumn('customer_id')) {
            return;
        }

        $this->addSql('ALTER TABLE sinusbot_nodes ADD customer_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_sinusbot_nodes_customer_id ON sinusbot_nodes (customer_id)');
        $this->addSql('ALTER TABLE sinusbot_nodes ADD CONSTRAINT fk_sinusbot_nodes_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('sinusbot_nodes')) {
            return;
        }

        $table = $schema->getTable('sinusbot_nodes');
        if (!$table->hasColumn('customer_id')) {
            return;
        }

        $this->dropForeignKey('sinusbot_nodes', 'customer_id', 'users');
        $this->addSql('DROP INDEX idx_sinusbot_nodes_customer_id ON sinusbot_nodes');
        $this->addSql('ALTER TABLE sinusbot_nodes DROP customer_id');
    }

    private function dropForeignKey(string $table, string $column, string $referencedTable): void
    {
        $constraint = $this->connection->fetchOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ?',
            [$table, $column, $referencedTable],
        );

        if (is_string($constraint) && $constraint !== '') {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $constraint));
        }
    }
}
