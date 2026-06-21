<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260621120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure ts6_instances.update_channel default matches the beta entity mapping';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql("ALTER TABLE ts6_instances MODIFY update_channel VARCHAR(16) NOT NULL DEFAULT 'beta'");

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('PRAGMA writable_schema = ON');
            $this->addSql("UPDATE sqlite_master SET sql = REPLACE(REPLACE(sql, \"update_channel VARCHAR(16) DEFAULT 'stable' NOT NULL\", \"update_channel VARCHAR(16) DEFAULT 'beta' NOT NULL\"), \"update_channel VARCHAR(16) NOT NULL DEFAULT 'stable'\", \"update_channel VARCHAR(16) NOT NULL DEFAULT 'beta'\") WHERE type = 'table' AND name = 'ts6_instances'");
            $this->addSql('PRAGMA writable_schema = OFF');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql("ALTER TABLE ts6_instances MODIFY update_channel VARCHAR(16) NOT NULL DEFAULT 'stable'");

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('PRAGMA writable_schema = ON');
            $this->addSql("UPDATE sqlite_master SET sql = REPLACE(REPLACE(sql, \"update_channel VARCHAR(16) DEFAULT 'beta' NOT NULL\", \"update_channel VARCHAR(16) DEFAULT 'stable' NOT NULL\"), \"update_channel VARCHAR(16) NOT NULL DEFAULT 'beta'\", \"update_channel VARCHAR(16) NOT NULL DEFAULT 'stable'\") WHERE type = 'table' AND name = 'ts6_instances'");
            $this->addSql('PRAGMA writable_schema = OFF');
        }
    }
}
