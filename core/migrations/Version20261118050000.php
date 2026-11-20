<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118050000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add cancellable minute-based rental contracts, exact wallet accounting and fixed domain periods without rewriting existing rentals.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('shop_product_variants') && !$schema->getTable('shop_product_variants')->hasColumn('slots')) {
            $schema->getTable('shop_product_variants')->addColumn('slots', 'integer', ['notnull' => false]);
        }
        $products = $schema->getTable('shop_products');
        if (!$products->hasColumn('kind')) {
            $products->addColumn('kind', 'string', ['length' => 255, 'default' => 'gameserver']);
        }
        if (!$products->hasColumn('provisioning_options')) {
            $products->addColumn('provisioning_options', 'json', ['notnull' => false]);
        }
        $products->getColumn('template_id')->setNotnull(false);
        $products->getColumn('node_id')->setNotnull(false);
        if (!$schema->hasTable('rental_accounts')) {
            $table = $schema->createTable('rental_accounts');
            $table->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('balance_units', 'bigint', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('reserved_units', 'bigint', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('postpaid_carry_units', 'bigint', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('wallet_carry_units', 'bigint', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('credit_limit_cents', 'integer', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('next_invoice_at', 'datetime_immutable', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('currency', 'string', ['notnull' => true, 'length' => 3, 'autoincrement' => false]);
            $table->addColumn('customer_id', 'integer', ['notnull' => true, 'autoincrement' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['customer_id', 'currency'], 'uniq_rental_account_owner_currency');
            $table->addIndex(['customer_id'], 'IDX_FAAE879F9395C3F3');
            $table->addForeignKeyConstraint('users', ['customer_id'], ['id'], [], 'FK_FAAE879F9395C3F3');
        }
        if (!$schema->hasTable('rental_credits')) {
            $table = $schema->createTable('rental_credits');
            $table->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('amount_cents', 'integer', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('reference', 'string', ['notnull' => true, 'length' => 120, 'autoincrement' => false]);
            $table->addColumn('reason', 'string', ['notnull' => true, 'length' => 500, 'autoincrement' => false]);
            $table->addColumn('account_id', 'integer', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('actor_id', 'integer', ['notnull' => true, 'autoincrement' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['reference'], 'uniq_rental_credit_reference');
            $table->addIndex(['account_id'], 'IDX_92F10B919B6B5FBA');
            $table->addIndex(['actor_id'], 'IDX_92F10B9110DAF24A');
            $table->addForeignKeyConstraint('rental_accounts', ['account_id'], ['id'], [], 'FK_92F10B919B6B5FBA');
            $table->addForeignKeyConstraint('users', ['actor_id'], ['id'], [], 'FK_92F10B9110DAF24A');
        }
        if (!$schema->hasTable('shop_contracts')) {
            $table = $schema->createTable('shop_contracts');
            $table->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('status', 'string', ['notnull' => true, 'length' => 24, 'autoincrement' => false]);
            $table->addColumn('resource_id', 'string', ['notnull' => false, 'length' => 120, 'autoincrement' => false]);
            $table->addColumn('job_id', 'string', ['notnull' => false, 'length' => 64, 'autoincrement' => false]);
            $table->addColumn('job_system', 'string', ['notnull' => false, 'length' => 16, 'autoincrement' => false]);
            $table->addColumn('auxiliary_jobs', 'json', ['notnull' => false, 'autoincrement' => false]);
            $table->addColumn('stop_jobs', 'json', ['notnull' => false, 'autoincrement' => false]);
            $table->addColumn('stop_job_id', 'string', ['notnull' => false, 'length' => 64, 'autoincrement' => false]);
            $table->addColumn('stop_job_system', 'string', ['notnull' => false, 'length' => 16, 'autoincrement' => false]);
            $table->addColumn('reservation_key', 'string', ['notnull' => false, 'length' => 255, 'autoincrement' => false]);
            $table->addColumn('started_at', 'datetime_immutable', ['notnull' => false, 'autoincrement' => false]);
            $table->addColumn('ended_at', 'datetime_immutable', ['notnull' => false, 'autoincrement' => false]);
            $table->addColumn('cancelled_at', 'datetime_immutable', ['notnull' => false, 'autoincrement' => false]);
            $table->addColumn('term_ends_at', 'datetime_immutable', ['notnull' => false, 'autoincrement' => false]);
            $table->addColumn('charged_minutes', 'integer', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('pending_units', 'bigint', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('reserved_units', 'bigint', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('invoiced_minutes', 'integer', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('error', 'string', ['notnull' => false, 'length' => 120, 'autoincrement' => false]);
            $table->addColumn('checked_at', 'datetime_immutable', ['notnull' => false, 'autoincrement' => false]);
            $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('updated_at', 'datetime_immutable', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('kind', 'string', ['notnull' => true, 'length' => 255, 'autoincrement' => false]);
            $table->addColumn('billing_mode', 'string', ['notnull' => true, 'length' => 12, 'autoincrement' => false]);
            $table->addColumn('configuration', 'json', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('monthly_cents', 'integer', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('order_id', 'integer', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('account_id', 'integer', ['notnull' => true, 'autoincrement' => false]);
            $table->addUniqueIndex(['order_id'], 'UNIQ_10E727F88D9F6D38');
            $table->setPrimaryKey(['id']);
            $table->addIndex(['status', 'checked_at'], 'idx_contract_work');
            $table->addIndex(['kind', 'resource_id'], 'idx_contract_resource');
            $table->addIndex(['account_id', 'status'], 'idx_contract_account');
            $table->addUniqueIndex(['reservation_key'], 'uniq_contract_reservation');
            $table->addIndex(['account_id'], 'IDX_10E727F89B6B5FBA');
            $table->addForeignKeyConstraint('shop_orders', ['order_id'], ['id'], [], 'FK_10E727F88D9F6D38');
            $table->addForeignKeyConstraint('rental_accounts', ['account_id'], ['id'], [], 'FK_10E727F89B6B5FBA');
        }
        if (!$schema->hasTable('shop_domain_terms')) {
            $table = $schema->createTable('shop_domain_terms');
            $table->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('reference', 'string', ['notnull' => true, 'length' => 120, 'autoincrement' => false]);
            $table->addColumn('starts_at', 'datetime_immutable', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('ends_at', 'datetime_immutable', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('amount_cents', 'integer', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('contract_id', 'integer', ['notnull' => true, 'autoincrement' => false]);
            $table->addColumn('actor_id', 'integer', ['notnull' => true, 'autoincrement' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['contract_id', 'reference'], 'uniq_domain_term_reference');
            $table->addIndex(['contract_id'], 'IDX_46BC70352576E0FD');
            $table->addIndex(['actor_id'], 'IDX_46BC703510DAF24A');
            $table->addForeignKeyConstraint('shop_contracts', ['contract_id'], ['id'], [], 'FK_46BC70352576E0FD');
            $table->addForeignKeyConstraint('users', ['actor_id'], ['id'], [], 'FK_46BC703510DAF24A');
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve rental contracts, invoices, wallet credits and confirmed registration periods.');
    }
}
