<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121110000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store node-level Proxmox address pools and exclusive IPv4 or IPv6 leases for tracked installations.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $pool = $schema->createTable('proxmox_ip_pools');
        $pool->addColumn('id', 'integer', ['autoincrement' => true]);
        $pool->addColumn('cluster_id', 'integer');
        $pool->addColumn('node', 'string', ['length' => 64]);
        $pool->addColumn('first_address', 'string', ['length' => 45]);
        $pool->addColumn('last_address', 'string', ['length' => 45]);
        $pool->addColumn('enabled', 'boolean');
        $pool->setPrimaryKey(['id']);
        $pool->addForeignKeyConstraint('proxmox_clusters', ['cluster_id'], ['id']);

        $lease = $schema->createTable('proxmox_ip_leases');
        $lease->addColumn('id', 'integer', ['autoincrement' => true]);
        $lease->addColumn('task_id', 'integer');
        $lease->addColumn('address_key', 'string', ['length' => 32]);
        $lease->setPrimaryKey(['id']);
        $lease->addForeignKeyConstraint('proxmox_tasks', ['task_id'], ['id']);

        foreach ([$pool, $lease] as $table) {
            $table->addColumn('family', 'integer');
            $table->addColumn('prefix', 'integer');
            $table->addColumn('gateway', 'string', ['length' => 45]);
            $table->addColumn('bridge', 'string', ['length' => 32]);
            $table->addColumn('vlan', 'integer', ['notnull' => false]);
        }

        $pool->addUniqueIndex(['cluster_id', 'node', 'family'], 'uniq_pve_ip_pool_node_family');
        $lease->addUniqueIndex(['family', 'address_key'], 'uniq_pve_ip_address');
        $lease->addUniqueIndex(['task_id', 'family'], 'uniq_pve_ip_lease_task_family');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Active guests may still use their reserved addresses.');
    }
}
