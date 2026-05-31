<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create scheduled_task_runs table for persisting schedule history and errors.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('scheduled_task_runs')) {
            $this->write('  scheduled_task_runs already exists – skipping.');
            return;
        }

        $this->addSql('CREATE TABLE scheduled_task_runs (id INT AUTO_INCREMENT NOT NULL, schedule_source VARCHAR(64) NOT NULL, schedule_id VARCHAR(64) NOT NULL, name VARCHAR(160) NOT NULL, type VARCHAR(120) NOT NULL, module VARCHAR(80) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, status VARCHAR(32) NOT NULL, message LONGTEXT DEFAULT NULL, created_job_ids JSON NOT NULL, duration_ms INT DEFAULT NULL, INDEX idx_scheduled_task_runs_schedule (schedule_source, schedule_id, started_at), INDEX idx_scheduled_task_runs_type (type, started_at), INDEX idx_scheduled_task_runs_status_started_at (status, started_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS scheduled_task_runs');
    }
}
