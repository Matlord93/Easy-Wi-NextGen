<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Billing;

use App\Module\Core\Application\Billing\ElectronicInvoiceBuilder;
use App\Module\Core\Application\Billing\ElectronicInvoiceValidator;
use App\Module\Core\Application\Billing\InvoicePdfRenderer;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use PHPUnit\Framework\TestCase;

/** Real offline standards checks using the pinned local validators. */
final class ElectronicInvoiceStandardsTest extends TestCase
{
    private function validator(): ElectronicInvoiceValidator
    {
        $directory = getenv('EASYWI_TEST_EINVOICE_VALIDATORS');
        if (false === $directory || '' === $directory) {
            $directory = dirname(__DIR__, 5).'/var/einvoice-validator';
        }
        $validator = new ElectronicInvoiceValidator($directory);
        self::assertTrue($validator->ready('zugferd'), 'Run python3 ../scripts/setup-einvoice-validation.py before the test suite.');

        return $validator;
    }

    public function testXRechnungWithMixedRatesSmallQuantitiesAndAllExemptionModes(): void
    {
        $validator = $this->validator();
        $builder = new ElectronicInvoiceBuilder();
        $cases = [ElectronicInvoiceBuilderTest::invoice()];
        foreach (['small_business', 'exempt', 'reverse_charge'] as $mode) {
            $cases[] = ElectronicInvoiceBuilderTest::invoice($mode);
        }
        $cases[] = ElectronicInvoiceBuilderTest::invoice('standard', array_fill(0, 100, ['description' => 'Kleinposition', 'quantity' => '1', 'unit_price' => '0.03', 'tax_rate' => '19']));
        $cases[] = ElectronicInvoiceBuilderTest::invoice('standard', [
            ['description' => 'Millisekundengenaue Abrechnung', 'quantity' => '0.001', 'unit_price' => '10', 'tax_rate' => '19'],
            ['description' => 'Kostenlose Zusatzleistung', 'quantity' => '1', 'unit_price' => '0', 'tax_rate' => '0'],
        ]);
        foreach ($cases as $invoice) {
            $validator->validate($builder->build($invoice, 'xrechnung')->getContent(), 'xrechnung');
            self::addToAssertionCount(1);
        }
    }

    public function testZugferdPassesEn16931AndPdfA3bAndEmbedsTheSameXml(): void
    {
        $validator = $this->validator();
        $document = (new ElectronicInvoiceBuilder())->build(ElectronicInvoiceBuilderTest::invoice(), 'zugferd');
        $pdf = (new InvoicePdfRenderer())->render('<h1>Rechnung</h1><p>Virtueller Server – November: 11,90 EUR<br>Zusätzlicher Speicher: 1,07 EUR<br>Summe: 12,97 EUR</p>');
        $contents = ZugferdDocumentPdfBuilder::fromPdfString($document, $pdf)->setPdfAConformanceLevelToBasic()->generateDocument()->downloadString();
        $validator->validate($document->getContent(), 'zugferd', $contents);
        self::assertSame($document->getContent(), \horstoeko\zugferd\ZugferdDocumentPdfReader::getXmlFromContent($contents));
    }

    public function testSemanticMismatchAndNonPdfAFileAreRejected(): void
    {
        $validator = $this->validator();
        $document = (new ElectronicInvoiceBuilder())->build(ElectronicInvoiceBuilderTest::invoice(), 'xrechnung');
        $xml = str_replace('<ram:GrandTotalAmount>12.97</ram:GrandTotalAmount>', '<ram:GrandTotalAmount>13.97</ram:GrandTotalAmount>', $document->getContent());
        self::assertNotSame($document->getContent(), $xml);
        try {
            $validator->validate($xml, 'xrechnung');
            self::fail('A semantic VAT/total mismatch must not be archived.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('billing.einvoice_rules_failed', $exception->getMessage());
        }
        $document = (new ElectronicInvoiceBuilder())->build(ElectronicInvoiceBuilderTest::invoice(), 'zugferd');
        $this->expectException(\InvalidArgumentException::class);
        $validator->validate($document->getContent(), 'zugferd', (new InvoicePdfRenderer())->render('<p>Ordinary PDF without PDF/A metadata</p>'));
    }
}
