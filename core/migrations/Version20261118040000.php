<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118040000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add immutable shop checkout snapshots, paid fulfillment, subscriptions and payment checkout bindings; preserve historical orders.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $fields = [
            'shop_products' => ['subscription_enabled' => ['boolean', ['default' => false]]],
            'shop_categories' => ['description' => ['text', ['notnull' => false]], 'active' => ['boolean', ['default' => true]]],
            'shop_rentals' => ['auto_renew' => ['boolean', ['default' => false]], 'renewal_configuration' => ['json', ['notnull' => false]]],
            'shop_orders' => ['invoice_id' => ['integer', ['notnull' => false]], 'rental_id' => ['integer', ['notnull' => false]], 'coupon_id' => ['integer', ['notnull' => false]], 'request_key' => ['string', ['length' => 64, 'notnull' => false]], 'renewal_for' => ['datetime_immutable', ['notnull' => false]], 'configuration' => ['json', ['notnull' => false]], 'processing_error' => ['string', ['length' => 120, 'notnull' => false]], 'last_attempt_at' => ['datetime_immutable', ['notnull' => false]]],
        ];
        foreach ($fields as $name => $columns) {
            $table = $schema->getTable($name);
            foreach ($columns as $column => [$type, $options]) {
                if (!$table->hasColumn($column)) {
                    $table->addColumn($column, $type, $options);
                }
            }
        }
        $orders = $schema->getTable('shop_orders');
        foreach (['invoice_id' => 'invoices', 'rental_id' => 'shop_rentals', 'coupon_id' => 'shop_coupons'] as $column => $target) {
            $name = 'fk_shop_order_'.$column;
            if (!$orders->hasForeignKey($name)) {
                $orders->addForeignKeyConstraint($target, [$column], ['id'], [], $name);
            }
        }
        foreach ([['shop_orders', 'uniq_shop_order_request', ['request_key'], true], ['shop_orders', 'uniq_shop_order_renewal', ['rental_id', 'renewal_for'], true], ['shop_orders', 'idx_shop_order_processing', ['status', 'created_at'], false], ['shop_rentals', 'idx_shop_rental_renewal', ['auto_renew', 'expires_at'], false]] as [$name, $index, $columns, $unique]) {
            $table = $schema->getTable($name);
            if (!$table->hasIndex($index)) {
                if ($unique) {
                    $table->addUniqueIndex($columns, $index);
                } else {
                    $table->addIndex($columns, $index);
                }
            }
        }
        if (!$schema->hasTable('payment_checkouts')) {
            $table = $schema->createTable('payment_checkouts');
            $table->addColumn('id', 'string', ['length' => 48]);
            $table->setPrimaryKey(['id']);
            $table->addColumn('invoice_id', 'integer');
            $table->addColumn('provider', 'string', ['length' => 20]);
            $table->addColumn('reference', 'string', ['length' => 120]);
            $table->addColumn('amount_cents', 'integer');
            $table->addColumn('currency', 'string', ['length' => 3]);
            $table->addColumn('completed', 'boolean');
            $table->addColumn('created_at', 'datetime_immutable');
            $table->addForeignKeyConstraint('invoices', ['invoice_id'], ['id'], [], 'fk_checkout_invoice');
            $table->addUniqueIndex(['provider', 'reference'], 'uniq_checkout_provider_reference');
            $table->addIndex(['provider', 'completed', 'created_at'], 'idx_checkout_pending');
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve financial records, checkout references and subscription history.');
    }
}
