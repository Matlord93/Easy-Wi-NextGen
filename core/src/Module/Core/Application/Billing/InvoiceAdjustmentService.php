<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class InvoiceAdjustmentService
{
    public function __construct(private EntityManagerInterface $em, private CreditNoteIssuer $credits, private AuditLogger $audit)
    {
    }

    public function credit(Invoice $invoice, int $amount, string $reason, User $actor): void
    {
        $this->adjust($invoice, $amount, $reason, $actor, false);
    }

    public function cancel(Invoice $invoice, string $reason, User $actor): void
    {
        $this->adjust($invoice, null, $reason, $actor, true);
    }

    private function adjust(Invoice $invoice, ?int $amount, string $reason, User $actor, bool $cancel): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 255) {
            throw new \InvalidArgumentException('billing.invalid_cancellation');
        }
        $this->em->wrapInTransaction(function () use ($invoice, $amount, $reason, $actor, $cancel): void {
            $this->em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
            if ($invoice->getStatus() === \App\Module\Core\Domain\Enum\InvoiceStatus::Cancelled) {
                throw new \DomainException('billing.cancelled_invoice');
            }
            $amount = $cancel ? $invoice->getAmountDueCents() : $amount;
            $wasPaid = $invoice->getPaidAt() !== null;
            if ($amount !== null && $amount > 0) {
                $this->credits->issue($invoice, 'CN-'.date('Ymd').'-'.bin2hex(random_bytes(6)), $amount, $invoice->getCurrency(), $reason, $actor);
            } elseif (!$cancel) {
                throw new \InvalidArgumentException('billing.invalid_credit');
            }
            if ($cancel) {
                $invoice->cancel($reason);
                if (!$wasPaid) {
                    $invoice->clearPaidAt();
                }
                $this->audit->log($actor, 'billing.invoice.cancelled', ['invoice_id' => $invoice->getId(), 'reason' => $reason]);
            }
        });
    }
}
