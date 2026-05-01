<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260501120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add extract_subdir and install_mode columns to game_template_plugins table';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('game_template_plugins')) {
            return;
        }

        $table = $schema->getTable('game_template_plugins');
        if (!$table->hasColumn('extract_subdir')) {
            $this->addSql("ALTER TABLE game_template_plugins ADD extract_subdir VARCHAR(128) DEFAULT NULL");
        }
        if (!$table->hasColumn('install_mode')) {
            $this->addSql("ALTER TABLE game_template_plugins ADD install_mode VARCHAR(32) NOT NULL DEFAULT 'extract'");
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('game_template_plugins')) {
            return;
        }

        $table = $schema->getTable('game_template_plugins');
        if ($table->hasColumn('extract_subdir')) {
            $this->addSql("ALTER TABLE game_template_plugins DROP COLUMN extract_subdir");
        }
        if ($table->hasColumn('install_mode')) {
            $this->addSql("ALTER TABLE game_template_plugins DROP COLUMN install_mode");
        }
    }
}
