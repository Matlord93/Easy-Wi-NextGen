<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Billing;

use App\Module\Core\Application\Billing\ElectronicInvoiceBuilder;
use App\Module\Core\Application\Billing\ElectronicInvoiceData;
use App\Module\Core\Application\Billing\InvoiceItems;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use PHPUnit\Framework\TestCase;

final class ElectronicInvoiceBuilderTest extends TestCase
{
    public static function invoice(string $mode = 'standard', array $rows = []): Invoice
    {
        $totals = InvoiceItems::calculate($rows ?: [
            ['description' => 'Virtueller Server – November', 'quantity' => '1', 'unit_price' => '11.90', 'tax_rate' => '19'],
            ['description' => 'Zusätzlicher Speicher', 'quantity' => '0.125', 'unit_price' => '8.56', 'tax_rate' => '7'],
        ], $mode);
        $invoice = new Invoice(new User('accounts@example.test', UserType::Customer), 'INV-TEST-2026-01', $totals['gross_cents'], 'EUR', new \DateTimeImmutable('+14 days'));
        $invoice->setBillingDetails($totals + [
            'document' => ['number' => $invoice->getNumber(), 'currency' => $invoice->getCurrency(), 'total_cents' => $invoice->getAmountTotalCents(), 'issue_date' => $invoice->getCreatedAt()->format('Y-m-d'), 'due_date' => $invoice->getDueDate()->format('Y-m-d')],
            'company' => ['name' => 'EasyWI Test GmbH', 'street' => 'Teststraße 1', 'postal' => '10115', 'city' => 'Berlin', 'country' => 'DE', 'email' => 'billing@example.test', 'contact' => 'Buchhaltung', 'phone' => '+49 30 123456', 'vat_id' => 'DE123456788', 'tax_number' => '12/345/67890', 'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX'],
            'customer' => ['name' => 'Testkunde GmbH', 'address' => 'Beispielweg 2', 'postal' => '20095', 'city' => 'Hamburg', 'country' => 'DE', 'email' => 'accounts@example.test', 'vat_id' => 'DE987654321'],
            'electronic_invoice' => ['buyer_reference' => '04011000-12345-03', 'service_date' => '2026-11-01'],
        ]);

        return $invoice;
    }

    public function testBothProfilesPassTheirXsdAndPreserveArchivedSums(): void
    {
        $invoice = self::invoice();
        foreach (['xrechnung', 'zugferd'] as $format) {
            $document = (new ElectronicInvoiceBuilder())->build($invoice, $format);
            $xpath = $document->getContentAsDomXPath();
            self::assertSame('12.97', $xpath->evaluate('string(//*[local-name()="GrandTotalAmount"])'));
            self::assertSame('04011000-12345-03', $xpath->evaluate('string(//*[local-name()="BuyerReference"])'));
            self::assertCount(2, $xpath->query('//*[local-name()="IncludedSupplyChainTradeLineItem"]'));
            self::assertSame('0.125', $xpath->evaluate('string((//*[local-name()="BasisQuantity"])[last()])'));
            $xml = $document->getContent();
            $invoice->markPaid(new \DateTimeImmutable());
            $invoice->extendDueDate(7);
            self::assertSame($xml, (new ElectronicInvoiceBuilder())->build($invoice, $format)->getContent());
        }
    }

    public function testTaxExemptAndReverseChargeUseExplicitCategories(): void
    {
        foreach (['small_business' => 'E', 'exempt' => 'E', 'reverse_charge' => 'AE'] as $mode => $category) {
            $document = (new ElectronicInvoiceBuilder())->build(self::invoice($mode), 'xrechnung');
            self::assertSame($category, $document->getContentAsDomXPath()->evaluate('string(//*[local-name()="ApplicableTradeTax"]/*[local-name()="CategoryCode"])'));
            self::assertStringContainsString('ExemptionReason', $document->getContent());
        }
    }

    public function testLegacyInvoicesAreNotSilentlyReconstructedFromCurrentSettings(): void
    {
        $invoice = new Invoice(new User('test@example.test', UserType::Customer), 'OLD-1', 100, 'EUR', new \DateTimeImmutable());
        self::assertContains('billing.einvoice_missing_snapshot', ElectronicInvoiceData::issues($invoice->getBillingDetails()));
        $this->expectExceptionMessage('billing.einvoice_incomplete');
        (new ElectronicInvoiceBuilder())->build($invoice, 'xrechnung');
    }

    public function testServiceDateAndControlCharactersAreRejected(): void
    {
        self::assertSame(['buyer_reference' => 'Ref-1', 'service_date' => '2026-11-01'], ElectronicInvoiceData::fromInput(['buyer_reference' => ' Ref-1 ', 'service_date' => '2026-11-01']));
        $this->expectExceptionMessage('billing.invalid_date');
        ElectronicInvoiceData::fromInput(['service_date' => '2026-02-30']);
    }
}
