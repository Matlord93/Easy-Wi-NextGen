<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260517120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow customers to have multiple active SinusBot instances.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('sinusbot_instances')) {
            return;
        }

        $table = $schema->getTable('sinusbot_instances');
        if ($table->hasIndex('uniq_sinusbot_instance_customer')) {
            $this->addSql($this->isSqlite() ? 'DROP INDEX uniq_sinusbot_instance_customer' : 'DROP INDEX uniq_sinusbot_instance_customer ON sinusbot_instances');
        }
        if (!$table->hasIndex('idx_sinusbot_instances_customer')) {
            $this->addSql('CREATE INDEX idx_sinusbot_instances_customer ON sinusbot_instances (customer_id)');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('sinusbot_instances')) {
            return;
        }

        $table = $schema->getTable('sinusbot_instances');
        if ($table->hasIndex('idx_sinusbot_instances_customer')) {
            $this->addSql($this->isSqlite() ? 'DROP INDEX idx_sinusbot_instances_customer' : 'DROP INDEX idx_sinusbot_instances_customer ON sinusbot_instances');
        }
        if (!$table->hasIndex('uniq_sinusbot_instance_customer')) {
            $this->addSql('CREATE UNIQUE INDEX uniq_sinusbot_instance_customer ON sinusbot_instances (customer_id)');
        }
    }

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }
}
