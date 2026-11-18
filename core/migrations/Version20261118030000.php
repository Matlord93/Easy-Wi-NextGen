<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118030000 extends AbstractMigration
{
    private const INDEXES = [
        'agent_jobs' => ['idx_agent_jobs_retention', ['status', 'finished_at']],
        'jobs' => ['idx_jobs_status_updated', ['status', 'updated_at']],
        'metric_samples' => ['idx_metric_samples_retention', ['recorded_at']],
        'metric_aggregates' => ['idx_metric_aggregates_retention', ['bucket_start']],
        'instance_metric_samples' => ['idx_instance_metric_retention', ['collected_at']],
    ];
    #[\Override]
    public function getDescription(): string { return 'Add time indexes for bounded automatic job and metric retention (expand only).'; }
    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (self::INDEXES as $table => [$name, $columns]) {
            if ($schema->hasTable($table) && !$schema->getTable($table)->hasIndex($name)) {
                $schema->getTable($table)->addIndex($columns, $name);
            }
        }
    }
    #[\Override]
    public function down(Schema $schema): void
    {
        foreach (self::INDEXES as $table => [$name]) {
            if ($schema->hasTable($table) && $schema->getTable($table)->hasIndex($name)) {
                $schema->getTable($table)->dropIndex($name);
            }
        }
    }
}
