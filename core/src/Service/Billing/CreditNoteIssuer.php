<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\CreditNote;
use App\Entity\Invoice;
use App\Enum\CreditNoteStatus;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class CreditNoteIssuer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceStatusUpdater $invoiceStatusUpdater,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function issue(
        Invoice $invoice,
        string $number,
        int $amountCents,
        string $currency,
        ?string $reason = null,
        ?\App\Entity\User $actor = null,
        ?\DateTimeImmutable $issuedAt = null,
    ): CreditNote {
        $creditNote = new CreditNote($invoice, $number, $amountCents, $currency, $reason);
        $creditNote->markIssued($issuedAt);

        $invoice->addCreditNote($creditNote);
        $invoice->applyCredit($amountCents);

        $this->entityManager->persist($creditNote);
        $this->auditLogger->log($actor, 'billing.credit_note.issued', [
            'invoice_id' => $invoice->getId(),
            'credit_note_number' => $number,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'status' => CreditNoteStatus::Issued->value,
        ]);

        $this->invoiceStatusUpdater->syncStatus($invoice, $actor);

        return $creditNote;
    }
}
