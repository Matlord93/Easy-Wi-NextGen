<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510100000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Repair installs that missed core job and metric tables after interrupted migrations.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->isMySql()) {
            $this->write('Skipping migration on non-MySQL platform.');

            return;
        }

        $jobsAvailable = $this->ensureJobs($schema);
        $this->ensureJobResults($schema, $jobsAvailable);
        $this->ensureJobLogs($schema, $jobsAvailable);
        $this->ensureMetricSamples($schema);
        $this->ensurePortRanges($schema);
    }

    public function down(Schema $schema): void
    {
        // Repair-only migration: do not drop runtime data on rollback.
    }

    private function isMySql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof MySQLPlatform;
    }

    private function ensureJobs(Schema $schema): bool
    {
        if (!$schema->hasTable('jobs')) {
            $this->addSql('CREATE TABLE jobs (id VARCHAR(32) NOT NULL, type VARCHAR(120) NOT NULL, payload JSON NOT NULL, status VARCHAR(20) NOT NULL, progress INT DEFAULT NULL, attempts INT NOT NULL DEFAULT 0, max_attempts INT NOT NULL DEFAULT 3, claimed_by VARCHAR(64) DEFAULT NULL, claimed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_error LONGTEXT DEFAULT NULL, last_error_code VARCHAR(64) DEFAULT NULL, last_attempt_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', locked_by VARCHAR(120) DEFAULT NULL, locked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', lock_token VARCHAR(64) DEFAULT NULL, lock_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_jobs_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            return true;
        }

        $table = $schema->getTable('jobs');
        if (!$table->hasColumn('progress')) {
            $this->addSql('ALTER TABLE jobs ADD progress INT DEFAULT NULL');
        }
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
            $this->addSql('ALTER TABLE jobs ADD claimed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
        if (!$table->hasColumn('last_error')) {
            $this->addSql('ALTER TABLE jobs ADD last_error LONGTEXT DEFAULT NULL');
        }
        if (!$table->hasColumn('last_error_code')) {
            $this->addSql('ALTER TABLE jobs ADD last_error_code VARCHAR(64) DEFAULT NULL');
        }
        if (!$table->hasColumn('last_attempt_at')) {
            $this->addSql('ALTER TABLE jobs ADD last_attempt_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }

        return true;
    }

    private function ensureJobResults(Schema $schema, bool $jobsAvailable): void
    {
        $created = false;
        if (!$schema->hasTable('job_results')) {
            $this->addSql('CREATE TABLE job_results (id INT AUTO_INCREMENT NOT NULL, job_id VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, output JSON NOT NULL, completed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_job_results_job (job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $created = true;
        }

        if (!$jobsAvailable) {
            return;
        }

        if ($created || !$schema->getTable('job_results')->hasForeignKey('FK_JOB_RESULTS_JOB')) {
            $this->addSql('ALTER TABLE job_results ADD CONSTRAINT FK_JOB_RESULTS_JOB FOREIGN KEY (job_id) REFERENCES jobs (id)');
        }
    }

    private function ensureJobLogs(Schema $schema, bool $jobsAvailable): void
    {
        $created = false;
        if (!$schema->hasTable('job_logs')) {
            $this->addSql('CREATE TABLE job_logs (id INT AUTO_INCREMENT NOT NULL, job_id VARCHAR(32) NOT NULL, message VARCHAR(255) NOT NULL, progress INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_job_logs_job (job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $created = true;
        }

        if (!$jobsAvailable) {
            return;
        }

        if ($created || !$schema->getTable('job_logs')->hasForeignKey('fk_job_logs_job')) {
            $this->addSql('ALTER TABLE job_logs ADD CONSTRAINT fk_job_logs_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE CASCADE');
        }
    }

    private function ensureMetricSamples(Schema $schema): void
    {
        $created = false;
        if (!$schema->hasTable('metric_samples')) {
            $this->addSql('CREATE TABLE metric_samples (id INT AUTO_INCREMENT NOT NULL, agent_id VARCHAR(64) NOT NULL, recorded_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', cpu_percent DOUBLE PRECISION DEFAULT NULL, memory_percent DOUBLE PRECISION DEFAULT NULL, disk_percent DOUBLE PRECISION DEFAULT NULL, net_bytes_sent BIGINT DEFAULT NULL, net_bytes_recv BIGINT DEFAULT NULL, payload JSON DEFAULT NULL, INDEX idx_metric_samples_agent_id (agent_id), INDEX idx_metric_samples_recorded_at (recorded_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $created = true;
        }

        if (!$schema->hasTable('agents')) {
            return;
        }

        if ($created || !$schema->getTable('metric_samples')->hasForeignKey('FK_METRIC_SAMPLES_AGENT')) {
            $this->addSql('ALTER TABLE metric_samples ADD CONSTRAINT FK_METRIC_SAMPLES_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id)');
        }
    }

    private function ensurePortRanges(Schema $schema): void
    {
        if (!$schema->hasTable('port_ranges')) {
            $this->addSql('CREATE TABLE port_ranges (id INT AUTO_INCREMENT NOT NULL, node_id VARCHAR(64) NOT NULL, purpose VARCHAR(120) NOT NULL, protocol VARCHAR(8) NOT NULL, start_port INT NOT NULL, end_port INT NOT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_port_ranges_node_id (node_id), INDEX idx_port_ranges_protocol (protocol), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }
    }
}
