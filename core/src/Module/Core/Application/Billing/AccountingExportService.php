<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\Payment;

final class AccountingExportService
{
    /**
     * @param Invoice[]                                               $invoices
     * @param array<int, array{archived_year: int, pdf_hash: string}> $archiveMetadata
     */
    public function exportInvoices(array $invoices, array $archiveMetadata): string
    {
        $handle = fopen('php://temp', 'r+');
        if (false === $handle) {
            return '';
        }

        fputcsv($handle, [
            'Belegdatum',
            'Belegnummer',
            'Kunde',
            'Status',
            'Faelligkeit',
            'Bezahlt am',
            'Betrag',
            'Waehrung',
            'Archivjahr',
            'PDF-Hash',
            'Netto', 'Umsatzsteuer', 'Steuersatz', 'Steuerart', 'Gutgeschrieben', 'Offen', 'Stornogrund', 'Steueraufteilung',
        ], ';', '"', '');

        foreach ($invoices as $invoice) {
            $archive = $archiveMetadata[$invoice->getId() ?? 0] ?? null;

            fputcsv($handle, [
                $invoice->getCreatedAt()->format('Y-m-d'),
                $this->safeCell($invoice->getNumber()),
                $this->safeCell($invoice->getCustomer()->getEmail()),
                $invoice->getStatus()->value,
                $invoice->getDueDate()->format('Y-m-d'),
                $invoice->getPaidAt()?->format('Y-m-d') ?? '',
                $this->formatAmount($invoice->getAmountTotalCents()),
                $invoice->getCurrency(),
                $archive['archived_year'] ?? '',
                $archive['pdf_hash'] ?? '',
                $this->formatAmount($invoice->getBillingDetails()['net_cents'] ?? $invoice->getAmountTotalCents()),
                $this->formatAmount($invoice->getBillingDetails()['tax_cents'] ?? 0),
                !empty($invoice->getBillingDetails()['mixed_tax_rates']) ? 'mixed' : $this->formatAmount($invoice->getBillingDetails()['tax_rate_bps'] ?? 0),
                $invoice->getBillingDetails()['tax_mode'] ?? 'legacy',
                $this->formatAmount(array_sum(array_map(static fn ($credit) => $credit->getAmountCents(), $invoice->getCreditNotes()->toArray()))),
                $this->formatAmount($invoice->getOutstandingCents()),
                $this->safeCell($invoice->getCancellationReason() ?? ''),
                json_encode($invoice->getBillingDetails()['tax_breakdown'] ?? [], JSON_THROW_ON_ERROR),
            ], ';', '"', '');
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return false === $contents ? '' : $contents;
    }

    /**
     * @param Payment[] $payments
     */
    public function exportPayments(array $payments): string
    {
        $handle = fopen('php://temp', 'r+');
        if (false === $handle) {
            return '';
        }

        fputcsv($handle, [
            'Buchungsdatum',
            'Rechnung',
            'Kunde',
            'Provider',
            'Referenz',
            'Status',
            'Betrag',
            'Waehrung',
            'Erhalten am',
        ], ';', '"', '');

        foreach ($payments as $payment) {
            fputcsv($handle, [
                $payment->getCreatedAt()->format('Y-m-d'),
                $this->safeCell($payment->getInvoice()->getNumber()),
                $this->safeCell($payment->getInvoice()->getCustomer()->getEmail()),
                $this->safeCell($payment->getProvider()),
                $this->safeCell($payment->getReference()),
                $payment->getStatus()->value,
                $this->formatAmount($payment->getAmountCents()),
                $payment->getCurrency(),
                $payment->getReceivedAt()?->format('Y-m-d') ?? '',
            ], ';', '"', '');
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return false === $contents ? '' : $contents;
    }

    private function formatAmount(int $amountCents): string
    {
        return number_format($amountCents / 100, 2, '.', '');
    }

    private function safeCell(string $value): string
    {
        return preg_match('/^[\s]*[=+@\-\t\r\n]/', $value) ? "'".$value : $value;
    }
}
