<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Persist isolated VM/node monitoring samples and alert policies.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $schema->getTable('kvm_guest_assignments')->addColumn('assigned_at', 'datetime_immutable', ['notnull' => false]);
        $schema->getTable('proxmox_guest_assignments')->addColumn('assigned_at', 'datetime_immutable', ['notnull' => false]);
        $samples = $schema->createTable('infrastructure_samples');
        $samples->addColumn('resource_key', 'string', ['length' => 64]);
        $samples->addColumn('stream', 'string', ['length' => 4]);
        $samples->addColumn('bucket', 'bigint');
        $samples->addColumn('observed_at', 'bigint');
        $samples->addColumn('payload', 'json');
        $samples->setPrimaryKey(['resource_key', 'stream', 'bucket']);
        $samples->addIndex(['bucket'], 'idx_infra_sample_retention');
        $policy = $schema->createTable('infrastructure_monitor_policies');
        $policy->addColumn('resource_key', 'string', ['length' => 64]);
        $policy->addColumn('thresholds', 'json');
        $policy->addColumn('state', 'json');
        $policy->addColumn('revision', 'integer');
        $policy->setPrimaryKey(['resource_key']);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve monitoring history and alert configuration.');
    }
}
