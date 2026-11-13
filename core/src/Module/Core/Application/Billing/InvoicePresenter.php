<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Domain\Entity\Invoice;

final class InvoicePresenter
{
    public function present(Invoice $invoice): array
    {
        return [
            'id' => $invoice->getId(), 'number' => $invoice->getNumber(), 'status' => $invoice->getStatus()->value,
            'customer' => $invoice->getCustomer()->getEmail(), 'currency' => $invoice->getCurrency(),
            'amount_total' => $invoice->getAmountTotalCents(), 'amount_due' => $invoice->getOutstandingCents(),
            'paid_amount' => $invoice->getPaidAmountCents(), 'due_date' => $invoice->getDueDate(),
            'paid_at' => $invoice->getPaidAt(), 'created_at' => $invoice->getCreatedAt(), 'details' => $invoice->getBillingDetails(),
            'cancelled_at' => $invoice->getCancelledAt(), 'cancellation_reason' => $invoice->getCancellationReason(),
            'items' => InvoiceItems::forInvoice($invoice),
            'credit_available' => $invoice->getAmountDueCents(),
            'payments' => array_map(static fn ($payment) => [
                'provider' => $payment->getProvider(), 'reference' => $payment->getReference(), 'amount' => $payment->getAmountCents(),
                'status' => $payment->getStatus()->value, 'created_at' => $payment->getCreatedAt(),
            ], $invoice->getPayments()->toArray()),
            'credits' => array_map(static fn ($credit) => [
                'number' => $credit->getNumber(), 'amount' => $credit->getAmountCents(), 'reason' => $credit->getReason(),
                'status' => $credit->getStatus()->value, 'issued_at' => $credit->getIssuedAt(),
            ], $invoice->getCreditNotes()->toArray()),
            'reminders' => array_map(static fn ($reminder) => [
                'level' => $reminder->getLevel(), 'fee' => $reminder->getFeeCents(), 'status' => $reminder->getStatus()->value,
                'sent_at' => $reminder->getSentAt(), 'created_at' => $reminder->getCreatedAt(),
            ], $invoice->getReminders()->toArray()),
        ];
    }
}
