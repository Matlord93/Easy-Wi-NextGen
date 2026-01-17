<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\Payment;

final class AccountingExportService
{
    /**
     * @param Invoice[] $invoices
     * @param array<int, array{archived_year: int, pdf_hash: string}> $archiveMetadata
     */
    public function exportInvoices(array $invoices, array $archiveMetadata): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
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
        ], ';');

        foreach ($invoices as $invoice) {
            $archive = $archiveMetadata[$invoice->getId() ?? 0] ?? null;

            fputcsv($handle, [
                $invoice->getCreatedAt()->format('Y-m-d'),
                $invoice->getNumber(),
                $invoice->getCustomer()->getEmail(),
                $invoice->getStatus()->value,
                $invoice->getDueDate()->format('Y-m-d'),
                $invoice->getPaidAt()?->format('Y-m-d') ?? '',
                $this->formatAmount($invoice->getAmountTotalCents()),
                $invoice->getCurrency(),
                $archive['archived_year'] ?? '',
                $archive['pdf_hash'] ?? '',
            ], ';');
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents === false ? '' : $contents;
    }

    /**
     * @param Payment[] $payments
     */
    public function exportPayments(array $payments): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
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
        ], ';');

        foreach ($payments as $payment) {
            fputcsv($handle, [
                $payment->getCreatedAt()->format('Y-m-d'),
                $payment->getInvoice()->getNumber(),
                $payment->getInvoice()->getCustomer()->getEmail(),
                $payment->getProvider(),
                $payment->getReference(),
                $payment->getStatus()->value,
                $this->formatAmount($payment->getAmountCents()),
                $payment->getCurrency(),
                $payment->getReceivedAt()?->format('Y-m-d') ?? '',
            ], ';');
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents === false ? '' : $contents;
    }

    private function formatAmount(int $amountCents): string
    {
        return number_format($amountCents / 100, 2, '.', '');
    }
}
