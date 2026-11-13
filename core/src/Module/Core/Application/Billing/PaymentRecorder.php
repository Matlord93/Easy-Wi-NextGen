<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\Payment;
use App\Module\Core\Domain\Enum\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

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
        if ($amountCents <= 0 || $amountCents > 2_147_483_647 || $currency !== $invoice->getCurrency() || trim($provider) === '' || mb_strlen($provider) > 60 || trim($reference) === '' || mb_strlen($reference) > 120) {
            throw new \InvalidArgumentException('billing.invalid_payment');
        }
        return $this->entityManager->wrapInTransaction(function () use ($invoice, $provider, $reference, $amountCents, $currency, $status, $actor, $receivedAt): Payment {
            if ($invoice->getId() !== null) {
                $this->entityManager->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
            }
            $existing = $this->entityManager->getRepository(Payment::class)->findOneBy(['provider' => $provider, 'reference' => $reference]);
            if ($existing instanceof Payment) {
                if ($existing->getInvoice() !== $invoice || $existing->getAmountCents() !== $amountCents || $existing->getCurrency() !== $currency) {
                    throw new \InvalidArgumentException('billing.payment_reference_conflict');
                }
                if ($existing->getStatus() === PaymentStatus::Pending || ($existing->getStatus() === PaymentStatus::Failed && $status === PaymentStatus::Succeeded)) {
                    $existing->setStatus($status);
                    $existing->setReceivedAt($receivedAt);
                }
                $this->invoiceStatusUpdater->syncStatus($invoice, $actor);

                return $existing;
            }
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
        });
    }
}
