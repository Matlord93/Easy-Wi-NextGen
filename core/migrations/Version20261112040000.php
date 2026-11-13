<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add KVM customer ownership and single-use browser console grants.';
    }

    public function up(Schema $schema): void
    {
        $assignments = $schema->createTable('kvm_guest_assignments');
        // String foreign keys must use the existing agent ID collation, even
        // when the database's current default differs from older tables.
        foreach (['charset', 'collation'] as $option) {
            if ($schema->getTable('agents')->hasOption($option)) {
                $assignments->addOption($option, $schema->getTable('agents')->getOption($option));
            }
        }
        $assignments->addColumn('id', 'integer', ['autoincrement' => true]);
        $nodeId = $assignments->addColumn('node_id', 'string', ['length' => 64]);
        foreach ($schema->getTable('agents')->getColumn('id')->getPlatformOptions() as $key => $value) {
            $nodeId->setPlatformOption($key, $value);
        }
        $assignments->addColumn('vm_uuid', 'string', ['length' => 36]);
        $assignments->addColumn('customer_id', 'integer');
        $assignments->setPrimaryKey(['id']);
        $assignments->addUniqueIndex(['node_id', 'vm_uuid'], 'uniq_kvm_guest_owner');
        $assignments->addForeignKeyConstraint('agents', ['node_id'], ['id'], ['onDelete' => 'CASCADE']);
        $assignments->addForeignKeyConstraint('users', ['customer_id'], ['id'], ['onDelete' => 'CASCADE']);
        $sessions = $schema->createTable('vm_console_sessions');
        $sessions->addColumn('id', 'string', ['length' => 36]);
        $sessions->addColumn('customer_id', 'integer');
        $sessions->addColumn('provider', 'string', ['length' => 10]);
        $sessions->addColumn('assignment_id', 'integer');
        $sessions->addColumn('session_hash', 'string', ['length' => 64]);
        $sessions->addColumn('ticket_hash', 'string', ['length' => 64]);
        foreach (['created_at', 'connect_before', 'expires_at', 'claimed_at'] as $column) {
            $sessions->addColumn($column, 'datetime_immutable', ['notnull' => 'claimed_at' !== $column]);
        }
        $sessions->setPrimaryKey(['id']);
        $sessions->addUniqueIndex(['ticket_hash'], 'uniq_vm_console_ticket');
        $sessions->addIndex(['expires_at'], 'idx_vm_console_expiry');
        $sessions->addIndex(['customer_id', 'created_at'], 'idx_vm_console_customer_created');
        $sessions->addForeignKeyConstraint('users', ['customer_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve KVM customer ownership.');
    }
}
