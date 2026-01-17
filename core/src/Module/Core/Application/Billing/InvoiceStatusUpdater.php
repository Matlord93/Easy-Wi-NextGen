<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Enum\InvoiceStatus;
use App\Repository\PaymentRepository;
use App\Module\Core\Application\AuditLogger;

final class InvoiceStatusUpdater
{
    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function syncStatus(Invoice $invoice, ?\App\Module\Core\Domain\Entity\User $actor = null, ?\DateTimeImmutable $now = null): void
    {
        $now = $now ?? new \DateTimeImmutable();
        $paidAmount = $this->paymentRepository->sumSucceededForInvoice($invoice);
        $previousStatus = $invoice->getStatus();

        if ($paidAmount >= $invoice->getAmountDueCents()) {
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
