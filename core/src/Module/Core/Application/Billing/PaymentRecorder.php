<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\Payment;
use App\Module\Core\Domain\Enum\PaymentStatus;
use App\Module\Core\Application\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class PaymentRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceStatusUpdater $invoiceStatusUpdater,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function record(
        Invoice $invoice,
        string $provider,
        string $reference,
        int $amountCents,
        string $currency,
        PaymentStatus $status,
        ?\App\Module\Core\Domain\Entity\User $actor = null,
        ?\DateTimeImmutable $receivedAt = null,
    ): Payment {
        $payment = new Payment($invoice, $provider, $reference, $amountCents, $currency, $status);
        $payment->setReceivedAt($receivedAt);

        $invoice->addPayment($payment);

        $this->entityManager->persist($payment);
        $this->auditLogger->log($actor, 'billing.payment.recorded', [
            'invoice_id' => $invoice->getId(),
            'provider' => $provider,
            'reference' => $reference,
            'amount_cents' => $amountCents,
            'status' => $status->value,
        ]);

        $this->invoiceStatusUpdater->syncStatus($invoice, $actor);

        return $payment;
    }
}
