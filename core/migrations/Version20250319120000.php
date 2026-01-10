<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250319120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add backups, job logs, and schedule queue tracking.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('job_logs')) {
            $this->addSql('CREATE TABLE job_logs (id INT AUTO_INCREMENT NOT NULL, job_id VARCHAR(32) NOT NULL, message VARCHAR(255) NOT NULL, progress INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_job_logs_job (job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE job_logs ADD CONSTRAINT fk_job_logs_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE CASCADE');
        }

        if (!$schema->hasTable('backups')) {
            $this->addSql('CREATE TABLE backups (id INT AUTO_INCREMENT NOT NULL, definition_id INT NOT NULL, job_id VARCHAR(32) DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_backups_definition (definition_id), INDEX idx_backups_job (job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE backups ADD CONSTRAINT fk_backups_definition FOREIGN KEY (definition_id) REFERENCES backup_definitions (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE backups ADD CONSTRAINT fk_backups_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE SET NULL');
        }

        if ($schema->hasTable('instance_schedules') && !$schema->getTable('instance_schedules')->hasColumn('last_queued_at')) {
            $this->addSql('ALTER TABLE instance_schedules ADD last_queued_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }

        if ($schema->hasTable('backup_schedules') && !$schema->getTable('backup_schedules')->hasColumn('last_queued_at')) {
            $this->addSql('ALTER TABLE backup_schedules ADD last_queued_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('job_logs')) {
            $this->addSql('ALTER TABLE job_logs DROP FOREIGN KEY fk_job_logs_job');
            $this->addSql('DROP TABLE job_logs');
        }

        if ($schema->hasTable('backups')) {
            $this->addSql('ALTER TABLE backups DROP FOREIGN KEY fk_backups_definition');
            $this->addSql('ALTER TABLE backups DROP FOREIGN KEY fk_backups_job');
            $this->addSql('DROP TABLE backups');
        }

        if ($schema->hasTable('instance_schedules') && $schema->getTable('instance_schedules')->hasColumn('last_queued_at')) {
            $this->addSql('ALTER TABLE instance_schedules DROP last_queued_at');
        }

        if ($schema->hasTable('backup_schedules') && $schema->getTable('backup_schedules')->hasColumn('last_queued_at')) {
            $this->addSql('ALTER TABLE backup_schedules DROP last_queued_at');
        }
    }
}
