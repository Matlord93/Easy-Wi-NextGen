<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260801090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add database nodes and link databases to a node.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('database_nodes')) {
            $this->addSql('CREATE TABLE database_nodes (id INT AUTO_INCREMENT NOT NULL, agent_id VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, engine VARCHAR(30) NOT NULL, host VARCHAR(255) NOT NULL, port INT NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, health_status VARCHAR(20) NOT NULL, health_message LONGTEXT DEFAULT NULL, last_checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_8B8182524600C3E3 (agent_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE database_nodes ADD CONSTRAINT FK_8B8182524600C3E3 FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE');
        }

        if ($schema->hasTable('databases')) {
            $table = $schema->getTable('databases');
            if (!$table->hasColumn('database_node_id')) {
                $this->addSql('ALTER TABLE databases ADD database_node_id INT DEFAULT NULL');
                $this->addSql('ALTER TABLE databases ADD CONSTRAINT FK_7E6F5E6EA8AB0C83 FOREIGN KEY (database_node_id) REFERENCES database_nodes (id) ON DELETE SET NULL');
                $this->addSql('CREATE INDEX IDX_7E6F5E6EA8AB0C83 ON databases (database_node_id)');
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('databases')) {
            $table = $schema->getTable('databases');
            if ($table->hasColumn('database_node_id')) {
                $this->addSql('ALTER TABLE databases DROP FOREIGN KEY FK_7E6F5E6EA8AB0C83');
                $this->addSql('DROP INDEX IDX_7E6F5E6EA8AB0C83 ON databases');
                $this->addSql('ALTER TABLE databases DROP database_node_id');
            }
        }

        if ($schema->hasTable('database_nodes')) {
            $this->addSql('DROP TABLE database_nodes');
        }
    }
}
