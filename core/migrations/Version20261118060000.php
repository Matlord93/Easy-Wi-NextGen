<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118060000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add wallet funding receipts and ledger, capacity leases, bounded lifecycle operations, retention and AutoDNS reconciliation.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('wallet_topups')) {
            $table = $schema->createTable('wallet_topups');
            $table->addColumn('id', 'string', ['notnull' => true, 'length' => 32]);
            $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('paid_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('credited_cents', 'integer', ['notnull' => true]);
            $table->addColumn('amount_cents', 'integer', ['notnull' => true]);
            $table->addColumn('request_key', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('account_id', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['account_id', 'created_at'], 'idx_wallet_topup_account');
            $table->addUniqueIndex(['request_key'], 'uniq_wallet_topup_request');
            $table->addIndex(['account_id'], 'IDX_5585C2FB9B6B5FBA');
            $table->addForeignKeyConstraint('rental_accounts', ['account_id'], ['id'], [], 'FK_5585C2FB9B6B5FBA');
        }
        if (!$schema->hasTable('wallet_entries')) {
            $table = $schema->createTable('wallet_entries');
            $table->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('kind', 'string', ['notnull' => true, 'length' => 24]);
            $table->addColumn('units', 'bigint', ['notnull' => true]);
            $table->addColumn('reference', 'string', ['notnull' => true, 'length' => 160]);
            $table->addColumn('description', 'string', ['notnull' => true, 'length' => 500]);
            $table->addColumn('account_id', 'integer', ['notnull' => true]);
            $table->addColumn('actor_id', 'integer', ['notnull' => false]);
            $table->addColumn('contract_id', 'integer', ['notnull' => false]);
            $table->addColumn('invoice_id', 'integer', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['account_id', 'id'], 'idx_wallet_entry_history');
            $table->addUniqueIndex(['reference'], 'uniq_wallet_entry_reference');
            $table->addIndex(['account_id'], 'IDX_5CF996D39B6B5FBA');
            $table->addIndex(['actor_id'], 'IDX_5CF996D310DAF24A');
            $table->addIndex(['contract_id'], 'IDX_5CF996D32576E0FD');
            $table->addIndex(['invoice_id'], 'IDX_5CF996D32989F1FD');
            $table->addForeignKeyConstraint('rental_accounts', ['account_id'], ['id'], [], 'FK_5CF996D39B6B5FBA');
            $table->addForeignKeyConstraint('users', ['actor_id'], ['id'], [], 'FK_5CF996D310DAF24A');
            $table->addForeignKeyConstraint('shop_contracts', ['contract_id'], ['id'], [], 'FK_5CF996D32576E0FD');
            $table->addForeignKeyConstraint('invoices', ['invoice_id'], ['id'], [], 'FK_5CF996D32989F1FD');
        }
        if (!$schema->hasTable('rental_capacity_pools')) {
            $table = $schema->createTable('rental_capacity_pools');
            $table->addColumn('limits', 'json', ['notnull' => true]);
            $table->addColumn('enabled', 'boolean', ['notnull' => true]);
            $table->addColumn('id', 'string', ['notnull' => true, 'length' => 100]);
            $table->setPrimaryKey(['id']);
        }
        if (!$schema->hasTable('rental_capacity_leases')) {
            $table = $schema->createTable('rental_capacity_leases');
            $table->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('released_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('cpu', 'integer', ['notnull' => true]);
            $table->addColumn('ram_mib', 'integer', ['notnull' => true]);
            $table->addColumn('disk_mib', 'integer', ['notnull' => true]);
            $table->addColumn('ports', 'integer', ['notnull' => true]);
            $table->addColumn('ipv4', 'integer', ['notnull' => true]);
            $table->addColumn('slots', 'integer', ['notnull' => true]);
            $table->addColumn('systems', 'integer', ['notnull' => true]);
            $table->addColumn('resources', 'json', ['notnull' => true]);
            $table->addColumn('pool_id', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('contract_id', 'integer', ['notnull' => true]);
            $table->addUniqueIndex(['contract_id'], 'UNIQ_C20C18B92576E0FD');
            $table->setPrimaryKey(['id']);
            $table->addIndex(['pool_id', 'released_at'], 'idx_capacity_held');
            $table->addIndex(['pool_id'], 'IDX_C20C18B97B3406DF');
            $table->addForeignKeyConstraint('rental_capacity_pools', ['pool_id'], ['id'], [], 'FK_C20C18B97B3406DF');
            $table->addForeignKeyConstraint('shop_contracts', ['contract_id'], ['id'], [], 'FK_C20C18B92576E0FD');
        }
        if (!$schema->hasTable('rental_notices')) {
            $table = $schema->createTable('rental_notices');
            $table->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('sent_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('next_attempt_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('attempts', 'integer', ['notnull' => true]);
            $table->addColumn('event_key', 'string', ['notnull' => true, 'length' => 120]);
            $table->addColumn('title', 'string', ['notnull' => true, 'length' => 160]);
            $table->addColumn('body', 'string', ['notnull' => true, 'length' => 500]);
            $table->addColumn('action_url', 'string', ['notnull' => true, 'length' => 255]);
            $table->addColumn('recipient_id', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['sent_at', 'next_attempt_at'], 'idx_rental_notice_pending');
            $table->addUniqueIndex(['event_key'], 'uniq_rental_notice_event');
            $table->addIndex(['recipient_id'], 'IDX_BDCABB3DE92F8F78');
            $table->addForeignKeyConstraint('users', ['recipient_id'], ['id'], [], 'FK_BDCABB3DE92F8F78');
        }
        if (!$schema->hasTable('registrar_contacts')) {
            $table = $schema->createTable('registrar_contacts');
            $table->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('identity', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('contact_id', 'integer', ['notnull' => true]);
            $table->addColumn('customer_id', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['customer_id', 'identity'], 'uniq_registrar_contact_owner');
            $table->addIndex(['customer_id'], 'IDX_60456B7D9395C3F3');
            $table->addForeignKeyConstraint('users', ['customer_id'], ['id'], [], 'FK_60456B7D9395C3F3');
        }
        if (!$schema->hasTable('registrar_operations')) {
            $table = $schema->createTable('registrar_operations');
            $table->addColumn('id', 'string', ['notnull' => true, 'length' => 32]);
            $table->addColumn('status', 'string', ['notnull' => true, 'length' => 24]);
            $table->addColumn('job_id', 'string', ['notnull' => false, 'length' => 64]);
            $table->addColumn('error', 'string', ['notnull' => false, 'length' => 120]);
            $table->addColumn('checked_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('completed_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('observed_expiry', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('action', 'string', ['notnull' => true, 'length' => 16]);
            $table->addColumn('request_key', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('identity', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('authinfo_encrypted', 'text', ['notnull' => false]);
            $table->addColumn('accounted_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('submitted_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('contract_id', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['status', 'checked_at'], 'idx_registrar_operation_work');
            $table->addUniqueIndex(['request_key'], 'uniq_registrar_operation_key');
            $table->addIndex(['contract_id'], 'IDX_12A71F82576E0FD');
            $table->addForeignKeyConstraint('shop_contracts', ['contract_id'], ['id'], [], 'FK_12A71F82576E0FD');
        }
        $table = $schema->getTable('rental_accounts');
        if (!$table->hasColumn('postpaid_reserved_units')) {
            $table->addColumn('postpaid_reserved_units', 'bigint', ['default' => 0, 'notnull' => true]);
        }
        $table = $schema->getTable('shop_contracts');
        if (!$table->hasColumn('provisioning_deadline_at')) {
            $table->addColumn('provisioning_deadline_at', 'datetime_immutable', ['notnull' => false]);
        }
        if (!$table->hasColumn('next_attempt_at')) {
            $table->addColumn('next_attempt_at', 'datetime_immutable', ['notnull' => false]);
        }
        if (!$table->hasColumn('provision_attempts')) {
            $table->addColumn('provision_attempts', 'integer', ['default' => 0, 'notnull' => true]);
        }
        if (!$table->hasColumn('stop_attempts')) {
            $table->addColumn('stop_attempts', 'integer', ['default' => 0, 'notnull' => true]);
        }
        if (!$table->hasColumn('delete_after_at')) {
            $table->addColumn('delete_after_at', 'datetime_immutable', ['notnull' => false]);
        }
        if (!$table->hasColumn('purged_at')) {
            $table->addColumn('purged_at', 'datetime_immutable', ['notnull' => false]);
        }
        if (!$table->hasColumn('retention_hold')) {
            $table->addColumn('retention_hold', 'boolean', ['default' => false, 'notnull' => true]);
        }
        if (!$table->hasColumn('cleanup_jobs')) {
            $table->addColumn('cleanup_jobs', 'json', ['notnull' => false]);
        }
        if (!$table->hasColumn('cleanup_checked_at')) {
            $table->addColumn('cleanup_checked_at', 'datetime_immutable', ['notnull' => false]);
        }
        if (!$table->hasColumn('deletion_notice_at')) {
            $table->addColumn('deletion_notice_at', 'datetime_immutable', ['notnull' => false]);
        }
        if (!$table->hasColumn('backups_purged_at')) {
            $table->addColumn('backups_purged_at', 'datetime_immutable', ['notnull' => false]);
        }
        if (!$table->hasColumn('backup_cleanup_jobs')) {
            $table->addColumn('backup_cleanup_jobs', 'json', ['notnull' => false]);
        }
        if (!$table->hasIndex('idx_contract_cleanup')) {
            $table->addIndex(['status', 'cleanup_checked_at'], 'idx_contract_cleanup');
        }
        $table = $schema->getTable('payment_checkouts');
        if (!$table->hasColumn('topup_id')) {
            $table->addColumn('topup_id', 'string', ['notnull' => false, 'length' => 32]);
        }
        if (!$table->hasIndex('IDX_C9ECB499F8E9A2CF')) {
            $table->addIndex(['topup_id'], 'IDX_C9ECB499F8E9A2CF');
        }
        if (!$table->hasForeignKey('FK_C9ECB499F8E9A2CF')) {
            $table->addForeignKeyConstraint('wallet_topups', ['topup_id'], ['id'], [], 'FK_C9ECB499F8E9A2CF');
        }
        $table = $schema->getTable('backups');
        if (!$table->hasColumn('deleted_at')) {
            $table->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);
        }
        if (!$table->hasColumn('cleanup_job_id')) {
            $table->addColumn('cleanup_job_id', 'string', ['notnull' => false, 'length' => 32]);
        }
        if (!$table->hasIndex('IDX_C451D0192210B7ED')) {
            $table->addIndex(['cleanup_job_id'], 'IDX_C451D0192210B7ED');
        }
        if (!$table->hasForeignKey('FK_C451D0192210B7ED')) {
            $table->addForeignKeyConstraint('jobs', ['cleanup_job_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_C451D0192210B7ED');
        }
        $schema->getTable('payment_checkouts')->getColumn('invoice_id')->setNotnull(false);
        $schema->getTable('shop_domain_terms')->getColumn('actor_id')->setNotnull(false);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve funding receipts, wallet movements, capacity allocations and registrar proof.');
    }
}
