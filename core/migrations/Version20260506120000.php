<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260506120000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add scheduled task run history table for the central scheduler.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('scheduled_task_runs')) {
            return;
        }

        $this->addSql("CREATE TABLE scheduled_task_runs (
            id INT AUTO_INCREMENT NOT NULL,
            schedule_source VARCHAR(64) NOT NULL,
            schedule_id VARCHAR(64) NOT NULL,
            name VARCHAR(160) NOT NULL,
            type VARCHAR(120) NOT NULL,
            module VARCHAR(80) NOT NULL,
            started_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            finished_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            status VARCHAR(32) NOT NULL,
            message LONGTEXT DEFAULT NULL,
            created_job_ids JSON NOT NULL,
            duration_ms INT DEFAULT NULL,
            INDEX idx_scheduled_task_runs_schedule (schedule_source, schedule_id, started_at),
            INDEX idx_scheduled_task_runs_type (type, started_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('scheduled_task_runs')) {
            $this->addSql('DROP TABLE scheduled_task_runs');
        }
    }
}
