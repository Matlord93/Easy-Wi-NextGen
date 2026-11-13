<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Enum\InvoiceStatus;

final class InvoiceStatusUpdater
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function syncStatus(Invoice $invoice, ?\App\Module\Core\Domain\Entity\User $actor = null, ?\DateTimeImmutable $now = null): void
    {
        $now = $now ?? new \DateTimeImmutable();
        if (in_array($invoice->getStatus(), [InvoiceStatus::Cancelled, InvoiceStatus::Draft], true)) {
            return;
        }
        // Include newly recorded payments before the unit of work is flushed.
        $paidAmount = $invoice->getPaidAmountCents();
        $previousStatus = $invoice->getStatus();

        if ($paidAmount >= $invoice->getAmountDueCents()) {
            foreach ($invoice->getReminders() as $reminder) {
                $reminder->markResolved();
            }
            if ($previousStatus !== InvoiceStatus::Paid) {
                $invoice->markPaid($now);
                $this->auditLogger->log($actor, 'billing.invoice.paid', [
                    'invoice_id' => $invoice->getId(),
                    'paid_cents' => $paidAmount,
                ]);
            }
            return;
        }

        if ($previousStatus === InvoiceStatus::Paid) {
            $invoice->clearPaidAt();
        }

        $nextStatus = $invoice->getDueDate() <= $now ? InvoiceStatus::PastDue : InvoiceStatus::Open;
        if ($previousStatus !== $nextStatus) {
            $invoice->setStatus($nextStatus);
            $this->auditLogger->log($actor, 'billing.invoice.status_changed', [
                'invoice_id' => $invoice->getId(),
                'from' => $previousStatus->value,
                'to' => $nextStatus->value,
            ]);
        }
    }
}
