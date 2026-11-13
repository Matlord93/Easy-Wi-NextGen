<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\CreditNote;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Enum\CreditNoteStatus;
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
        ?\App\Module\Core\Domain\Entity\User $actor = null,
        ?\DateTimeImmutable $issuedAt = null,
    ): CreditNote {
        if ($invoice->getStatus() === \App\Module\Core\Domain\Enum\InvoiceStatus::Cancelled || $amountCents <= 0 || $amountCents > $invoice->getAmountDueCents() || $currency !== $invoice->getCurrency() || trim($number) === '' || mb_strlen($number) > 40 || mb_strlen($reason ?? '') > 255) {
            throw new \InvalidArgumentException('billing.invalid_credit');
        }
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
