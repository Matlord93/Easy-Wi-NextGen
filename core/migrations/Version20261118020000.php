<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118020000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Index active job lookups and chronological per-agent metric reads without changing stored data.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('jobs') && !$schema->getTable('jobs')->hasIndex('idx_core_jobs_type_status')) {
            $schema->getTable('jobs')->addIndex(['type', 'status'], 'idx_core_jobs_type_status');
        }
        if ($schema->hasTable('metric_samples') && !$schema->getTable('metric_samples')->hasIndex('idx_metric_samples_agent_recorded_at')) {
            $schema->getTable('metric_samples')->addIndex(['agent_id', 'recorded_at'], 'idx_metric_samples_agent_recorded_at');
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        if ($schema->hasTable('jobs') && $schema->getTable('jobs')->hasIndex('idx_core_jobs_type_status')) {
            $schema->getTable('jobs')->dropIndex('idx_core_jobs_type_status');
        }
        if ($schema->hasTable('metric_samples') && $schema->getTable('metric_samples')->hasIndex('idx_metric_samples_agent_recorded_at')) {
            $schema->getTable('metric_samples')->dropIndex('idx_metric_samples_agent_recorded_at');
        }
    }
}
