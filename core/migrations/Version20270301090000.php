<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20270301090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_panel_host and panel_vhost_path columns to webspace_nodes (backfill missed by ordering in Version20260509120000).';
    }

    public function up(Schema $schema): void
    {
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
