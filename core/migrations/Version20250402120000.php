<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20250402120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CMS maintenance settings to sites.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('sites')) {
            return;
        }

        $table = $schema->getTable('sites');
        if (!$table->hasColumn('maintenance_enabled')) {
            $this->addSql('ALTER TABLE sites ADD maintenance_enabled TINYINT(1) NOT NULL DEFAULT 0');
        }
        if (!$table->hasColumn('maintenance_message')) {
            $this->addSql('ALTER TABLE sites ADD maintenance_message LONGTEXT DEFAULT NULL');
        }
        if (!$table->hasColumn('maintenance_allowlist')) {
            $this->addSql('ALTER TABLE sites ADD maintenance_allowlist LONGTEXT DEFAULT NULL');
        }
        if (!$table->hasColumn('maintenance_starts_at')) {
            $this->addSql('ALTER TABLE sites ADD maintenance_starts_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
        if (!$table->hasColumn('maintenance_ends_at')) {
            $this->addSql('ALTER TABLE sites ADD maintenance_ends_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('sites')) {
            return;
        }

        $table = $schema->getTable('sites');
        if ($table->hasColumn('maintenance_ends_at')) {
            $this->addSql('ALTER TABLE sites DROP maintenance_ends_at');
        }
        if ($table->hasColumn('maintenance_starts_at')) {
            $this->addSql('ALTER TABLE sites DROP maintenance_starts_at');
        }
        if ($table->hasColumn('maintenance_allowlist')) {
            $this->addSql('ALTER TABLE sites DROP maintenance_allowlist');
        }
        if ($table->hasColumn('maintenance_message')) {
            $this->addSql('ALTER TABLE sites DROP maintenance_message');
        }
        if ($table->hasColumn('maintenance_enabled')) {
            $this->addSql('ALTER TABLE sites DROP maintenance_enabled');
        }
    }
}
