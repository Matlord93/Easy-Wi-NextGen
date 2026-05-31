<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260630120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bootstrap token invalidation and attempt tracking.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('agent_bootstrap_tokens')) {
            return;
        }

        $this->addSql('ALTER TABLE agent_bootstrap_tokens ADD invalidated_at DATETIME DEFAULT NULL, ADD last_used_at DATETIME DEFAULT NULL, ADD attempts_count INT NOT NULL DEFAULT 0, ADD max_attempts INT NOT NULL DEFAULT 5, CHANGE expires_at expires_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('agent_bootstrap_tokens')) {
            return;
        }

        $this->addSql('ALTER TABLE agent_bootstrap_tokens DROP invalidated_at, DROP last_used_at, DROP attempts_count, DROP max_attempts, CHANGE expires_at expires_at DATETIME NOT NULL');
    }
}
