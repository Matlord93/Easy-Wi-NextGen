<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509120000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add is_panel_host and panel_vhost_path to webspace_nodes to protect the panel vhost on co-located servers.';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }

        if (!$schema->hasTable('webspace_nodes')) {
            return;
        }

        $table = $schema->getTable('webspace_nodes');

        if (!$table->hasColumn('is_panel_host')) {
            $this->addSql('ALTER TABLE webspace_nodes ADD is_panel_host TINYINT(1) NOT NULL DEFAULT 0');
        }

        if (!$table->hasColumn('panel_vhost_path')) {
            $this->addSql('ALTER TABLE webspace_nodes ADD panel_vhost_path VARCHAR(255) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }

        if (!$schema->hasTable('webspace_nodes')) {
            return;
        }

        $table = $schema->getTable('webspace_nodes');

        if ($table->hasColumn('panel_vhost_path')) {
            $this->addSql('ALTER TABLE webspace_nodes DROP COLUMN panel_vhost_path');
        }

        if ($table->hasColumn('is_panel_host')) {
            $this->addSql('ALTER TABLE webspace_nodes DROP COLUMN is_panel_host');
        }
    }
}
