<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add encrypted Proxmox cluster connections, tracked operations and customer guest assignments.';
    }

    public function up(Schema $schema): void
    {
        $clusters = $schema->createTable('proxmox_clusters');
        $clusters->addColumn('id', 'integer', ['autoincrement' => true]);
        $clusters->addColumn('name', 'string', ['length' => 120]);
        $clusters->addColumn('base_url', 'string', ['length' => 255]);
        $clusters->addColumn('token_id', 'string', ['length' => 190]);
        $clusters->addColumn('encrypted_token', 'text');
        $clusters->addColumn('enabled', 'boolean');
        $clusters->addColumn('created_at', 'datetime_immutable');
        $clusters->setPrimaryKey(['id']);
        $tasks = $schema->createTable('proxmox_tasks');
        $tasks->addColumn('id', 'integer', ['autoincrement' => true]);
        $tasks->addColumn('cluster_id', 'integer');
        $tasks->addColumn('node', 'string', ['length' => 64]);
        $tasks->addColumn('kind', 'string', ['length' => 10]);
        $tasks->addColumn('vm_id', 'integer');
        $tasks->addColumn('action', 'string', ['length' => 40]);
        $tasks->addColumn('request_key', 'string', ['length' => 32]);
        $tasks->addColumn('upid', 'string', ['length' => 512, 'notnull' => false]);
        $tasks->addColumn('state', 'string', ['length' => 20]);
        $tasks->addColumn('guest_lock', 'string', ['length' => 100, 'notnull' => false]);
        $tasks->addColumn('result', 'string', ['length' => 64, 'notnull' => false]);
        $tasks->addColumn('created_at', 'datetime_immutable');
        $tasks->addColumn('finished_at', 'datetime_immutable', ['notnull' => false]);
        $tasks->addColumn('actor_id', 'integer', ['notnull' => false]);
        $tasks->setPrimaryKey(['id']);
        $tasks->addUniqueIndex(['cluster_id', 'request_key'], 'uniq_proxmox_request');
        $tasks->addUniqueIndex(['cluster_id', 'guest_lock'], 'uniq_proxmox_guest_lock');
        $tasks->addIndex(['state'], 'idx_proxmox_task_state');
        $tasks->addForeignKeyConstraint('proxmox_clusters', ['cluster_id'], ['id']);
        $assignments = $schema->createTable('proxmox_guest_assignments');
        $assignments->addColumn('id', 'integer', ['autoincrement' => true]);
        $assignments->addColumn('cluster_id', 'integer');
        $assignments->addColumn('vm_id', 'integer');
        $assignments->addColumn('kind', 'string', ['length' => 10]);
        $assignments->addColumn('customer_id', 'integer');
        $assignments->addColumn('identity_tag', 'string', ['length' => 64]);
        $assignments->addColumn('backup_storage', 'string', ['length' => 64, 'notnull' => false]);
        $assignments->setPrimaryKey(['id']);
        $assignments->addUniqueIndex(['cluster_id', 'vm_id'], 'uniq_proxmox_guest');
        $assignments->addForeignKeyConstraint('proxmox_clusters', ['cluster_id'], ['id']);
        $assignments->addForeignKeyConstraint('users', ['customer_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Cluster credentials, ownership and task history must be retained.');
    }
}
