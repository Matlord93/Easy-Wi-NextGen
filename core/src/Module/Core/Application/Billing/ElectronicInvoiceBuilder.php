<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Domain\Entity\Invoice;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdSettings;
use horstoeko\zugferd\ZugferdXsdValidator;

final class ElectronicInvoiceBuilder
{
    public function build(Invoice $invoice, string $format): ZugferdDocumentBuilder
    {
        if (!in_array($format, ['xrechnung', 'zugferd'], true)) {
            throw new \InvalidArgumentException('billing.einvoice_invalid_format');
        }
        $data = $invoice->getBillingDetails();
        if ([] !== ElectronicInvoiceData::issues($data, $format)) {
            throw new \InvalidArgumentException('billing.einvoice_incomplete');
        }
        $seller = $data['company'];
        $buyer = $data['customer'];
        ZugferdSettings::setQuantityDecimals(3);
        $document = ZugferdDocumentBuilder::createNew('xrechnung' === $format ? ZugferdProfiles::PROFILE_XRECHNUNG_3 : ZugferdProfiles::PROFILE_EN16931);
        $document->setDocumentInformation($data['document']['number'], '380', new \DateTimeImmutable($data['document']['issue_date']), $data['document']['currency'])
            ->setDocumentBusinessProcess('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0')
            ->setDocumentBuyerReference(($data['electronic_invoice']['buyer_reference'] ?? '') ?: null)
            ->setDocumentSeller($seller['name'])
            ->setDocumentSellerAddress($seller['street'], null, null, $seller['postal'], $seller['city'], $seller['country'])
            ->setDocumentSellerContact(($seller['contact'] ?? '') ?: null, null, ($seller['phone'] ?? '') ?: null, null, $seller['email'])
            ->setDocumentSellerCommunication('EM', $seller['email'])
            ->setDocumentBuyer($buyer['name'])
            ->setDocumentBuyerAddress($buyer['address'], null, null, $buyer['postal'], $buyer['city'], $buyer['country'])
            ->setDocumentBuyerCommunication('EM', $buyer['email'])
            ->setDocumentSupplyChainEvent(new \DateTimeImmutable($data['electronic_invoice']['service_date']))
            ->addDocumentPaymentMeanToCreditTransfer($seller['iban'], $seller['name'], null, ($seller['bic'] ?? '') ?: null, $data['document']['number'])
            ->addDocumentPaymentTerm(null, new \DateTimeImmutable($data['document']['due_date']));
        if (!empty($seller['vat_id'])) {
            $document->addDocumentSellerVATRegistrationNumber($seller['vat_id']);
        }
        if (!empty($seller['tax_number'])) {
            $document->addDocumentSellerTaxNumber($seller['tax_number']);
        }
        if (!empty($buyer['vat_id'])) {
            $document->addDocumentBuyerVATRegistrationNumber($buyer['vat_id']);
        }
        $mode = $data['tax_mode'];
        $reason = match ($mode) {
            'exempt' => 'Umsatzsteuerbefreite Leistung',
            'small_business' => 'Steuerbefreiung für Kleinunternehmer gemäß § 19 UStG',
            'reverse_charge' => 'Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge)',
            default => null,
        };
        foreach ($data['items'] as $index => $item) {
            $category = self::category($mode, $item['tax_rate_bps']);
            $quantity = $item['quantity_milli'] / 1000;
            // A price basis equal to the billed quantity preserves the archived integer line
            // total exactly, including quantities of 0.001 and gross-to-net rounding.
            $document->addNewPosition((string) ($index + 1))
                ->setDocumentPositionProductDetails(mb_substr($item['description'], 0, 200), $item['description'])
                ->setDocumentPositionNetPrice($item['net_cents'] / 100, $quantity, 'C62')
                ->setDocumentPositionQuantity($quantity, 'C62')
                ->addDocumentPositionTax($category, 'VAT', $item['tax_rate_bps'] / 100)
                ->setDocumentPositionLineSummation($item['net_cents'] / 100);
        }
        foreach ($data['tax_breakdown'] as $group) {
            $document->addDocumentTax(self::category($mode, $group['tax_rate_bps']), 'VAT', $group['net_cents'] / 100, $group['tax_cents'] / 100, $group['tax_rate_bps'] / 100, $reason);
        }
        // Later payments, credits and dunning fees must not rewrite an issued document.
        $document->setDocumentSummation($data['gross_cents'] / 100, $data['gross_cents'] / 100, $data['net_cents'] / 100, 0, 0, $data['net_cents'] / 100, $data['tax_cents'] / 100, 0, 0);
        if ((new ZugferdXsdValidator($document))->validate()->hasValidationErrors()) {
            throw new \InvalidArgumentException('billing.einvoice_schema_failed');
        }

        return $document;
    }

    private static function category(string $mode, int $rate): string
    {
        return match ($mode) {
            'reverse_charge' => 'AE',
            'small_business', 'exempt' => 'E',
            default => 0 === $rate ? 'Z' : 'S',
        };
    }
}
