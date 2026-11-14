<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Billing;

use App\Module\Core\Application\Billing\ElectronicInvoiceBuilder;
use App\Module\Core\Domain\Entity\Invoice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ElectronicInvoiceDataValidationTest extends TestCase
{
    #[DataProvider('invalidSnapshots')]
    public function testMalformedArchivedDataIsRejected(array $replacement): void
    {
        $invoice = ElectronicInvoiceBuilderTest::invoice();
        $invoice = $this->copyWithDetails($invoice, array_replace_recursive($invoice->getBillingDetails(), $replacement));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('billing.einvoice_incomplete');
        (new ElectronicInvoiceBuilder())->build($invoice, 'xrechnung');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSnapshots(): iterable
    {
        yield 'invalid issue date' => [['document' => ['issue_date' => '2026-02-30']]];
        yield 'relative due date' => [['document' => ['due_date' => 'tomorrow']]];
        yield 'amount is a string' => [['gross_cents' => '1297']];
        yield 'negative amount' => [['net_cents' => -1]];
        yield 'invalid country type' => [['company' => ['country' => ['DE']]]];
        yield 'invalid optional tax number' => [['company' => ['tax_number' => ['123']]]];
        yield 'invalid line description' => [['items' => [['description' => ['bad']]]]];
        yield 'zero quantity' => [['items' => [['quantity_milli' => 0]]]];
        yield 'tax rate above 100 percent' => [['tax_breakdown' => [['tax_rate_bps' => 10001]]]];
        yield 'invalid line container' => [['items' => 'not-an-array']];
        yield 'invalid party container' => [['company' => false]];
    }

    public function testZeroBuyerReferenceIsPreservedAndSnapshotIsNotMutated(): void
    {
        $invoice = ElectronicInvoiceBuilderTest::invoice();
        $details = $invoice->getBillingDetails();
        $details['electronic_invoice']['buyer_reference'] = '0';
        $invoice = $this->copyWithDetails($invoice, $details);

        $document = (new ElectronicInvoiceBuilder())->build($invoice, 'xrechnung');

        self::assertSame('0', $document->getContentAsDomXPath()->evaluate('string(//*[local-name()="BuyerReference"])'));
        self::assertSame($details, $invoice->getBillingDetails());
    }

    private function copyWithDetails(Invoice $source, array $details): Invoice
    {
        $invoice = new Invoice($source->getCustomer(), $source->getNumber(), $source->getAmountTotalCents(), $source->getCurrency(), $source->getDueDate());
        $invoice->setBillingDetails($details);

        return $invoice;
    }
}
