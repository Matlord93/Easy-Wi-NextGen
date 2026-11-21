<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121090000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Configure Proxmox VM ID ranges per node and reserve destination IDs while creation tasks are active.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $schema->getTable('proxmox_clusters')->addColumn('vm_id_ranges', 'json', ['notnull' => false]);
        $tasks = $schema->getTable('proxmox_tasks');
        $tasks->addColumn('target_vm_id', 'integer', ['notnull' => false]);
        $tasks->addColumn('target_lock', 'integer', ['notnull' => false]);
        $tasks->addColumn('installation', 'json', ['notnull' => false]);
        $tasks->addColumn('last_polled_at', 'datetime_immutable', ['notnull' => false]);
        $tasks->addUniqueIndex(['cluster_id', 'target_lock'], 'uniq_proxmox_target_lock');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $tasks = $schema->getTable('proxmox_tasks');
        $tasks->dropIndex('uniq_proxmox_target_lock');
        $tasks->dropColumn('target_lock');
        $tasks->dropColumn('target_vm_id');
        $tasks->dropColumn('installation');
        $tasks->dropColumn('last_polled_at');
        $schema->getTable('proxmox_clusters')->dropColumn('vm_id_ranges');
    }
}
