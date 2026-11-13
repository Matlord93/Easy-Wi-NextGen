<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add immutable invoice tax/address snapshots, cancellations and unique billing references.';
    }

    public function up(Schema $schema): void
    {
        foreach (['invoices' => 'number', 'credit_notes' => 'number', 'payments' => 'provider, reference'] as $table => $columns) {
            $duplicates = $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM (SELECT %s FROM %s GROUP BY %s HAVING COUNT(*) > 1) duplicate_references', $columns, $table, $columns));
            $this->abortIf((int) $duplicates > 0, sprintf('Duplicate references in %s must be reviewed before adding unique billing indexes. No records were deleted.', $table));
        }
        $this->addSql('ALTER TABLE invoices ADD billing_details JSON DEFAULT NULL, ADD cancelled_at DATETIME DEFAULT NULL, ADD cancellation_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_invoices_number ON invoices (number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_credit_notes_number ON credit_notes (number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_payments_provider_reference ON payments (provider, reference)');
        // Older manual "mark paid" actions only set a status. Preserve those
        // settlements when switching to payment-ledger based reconciliation.
        $this->addSql("INSERT INTO payments (invoice_id, provider, reference, amount_cents, currency, status, received_at, created_at) SELECT i.id, 'manual', CONCAT('legacy-manual-', i.id), i.amount_due_cents - COALESCE(p.paid, 0), i.currency, 'succeeded', COALESCE(i.paid_at, i.updated_at), i.updated_at FROM invoices i LEFT JOIN (SELECT invoice_id, SUM(amount_cents) AS paid FROM payments WHERE status = 'succeeded' GROUP BY invoice_id) p ON p.invoice_id = i.id WHERE i.status = 'paid' AND i.amount_due_cents > COALESCE(p.paid, 0)");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Invoice snapshots and cancellation history must be preserved.');
    }
}
