<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260429120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add watchdog_enabled column to instances table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('instances');
        if (!$table->hasColumn('watchdog_enabled')) {
            $this->addSql("ALTER TABLE instances ADD watchdog_enabled TINYINT(1) NOT NULL DEFAULT 0");
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('instances');
        if ($table->hasColumn('watchdog_enabled')) {
            $this->addSql("ALTER TABLE instances DROP COLUMN watchdog_enabled");
        }
    }
}
