<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260621110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change ts6_instances.update_channel column DEFAULT from stable to beta';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $this->addSql("ALTER TABLE ts6_instances MODIFY update_channel VARCHAR(16) NOT NULL DEFAULT 'beta'");
    }

    public function down(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $this->addSql("ALTER TABLE ts6_instances MODIFY update_channel VARCHAR(16) NOT NULL DEFAULT 'stable'");
    }
}
