<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260702090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add job tracking fields for claims, retries, and error details.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('jobs')) {
            return;
        }

        $table = $schema->getTable('jobs');

        if (!$table->hasColumn('attempts')) {
            $this->addSql('ALTER TABLE jobs ADD attempts INT NOT NULL DEFAULT 0');
        }
        if (!$table->hasColumn('max_attempts')) {
            $this->addSql('ALTER TABLE jobs ADD max_attempts INT NOT NULL DEFAULT 3');
        }
        if (!$table->hasColumn('claimed_by')) {
            $this->addSql('ALTER TABLE jobs ADD claimed_by VARCHAR(64) DEFAULT NULL');
        }
        if (!$table->hasColumn('claimed_at')) {
            $this->addSql('ALTER TABLE jobs ADD claimed_at DATETIME DEFAULT NULL');
        }
        if (!$table->hasColumn('last_error')) {
            $this->addSql('ALTER TABLE jobs ADD last_error LONGTEXT DEFAULT NULL');
        }
        if (!$table->hasColumn('last_error_code')) {
            $this->addSql('ALTER TABLE jobs ADD last_error_code VARCHAR(64) DEFAULT NULL');
        }
        if (!$table->hasColumn('last_attempt_at')) {
            $this->addSql('ALTER TABLE jobs ADD last_attempt_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('jobs')) {
            return;
        }

        $table = $schema->getTable('jobs');

        if ($table->hasColumn('attempts')) {
            $this->addSql('ALTER TABLE jobs DROP attempts');
        }
        if ($table->hasColumn('max_attempts')) {
            $this->addSql('ALTER TABLE jobs DROP max_attempts');
        }
        if ($table->hasColumn('claimed_by')) {
            $this->addSql('ALTER TABLE jobs DROP claimed_by');
        }
        if ($table->hasColumn('claimed_at')) {
            $this->addSql('ALTER TABLE jobs DROP claimed_at');
        }
        if ($table->hasColumn('last_error')) {
            $this->addSql('ALTER TABLE jobs DROP last_error');
        }
        if ($table->hasColumn('last_error_code')) {
            $this->addSql('ALTER TABLE jobs DROP last_error_code');
        }
        if ($table->hasColumn('last_attempt_at')) {
            $this->addSql('ALTER TABLE jobs DROP last_attempt_at');
        }
    }
}
