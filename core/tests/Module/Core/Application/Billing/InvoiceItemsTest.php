<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Billing;

use App\Module\Core\Application\Billing\InvoiceItems;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceItemsTest extends TestCase
{
    public function testMixedTaxRatesAndDecimalQuantitiesAreSummedPerLine(): void
    {
        $result = InvoiceItems::calculate([
            ['description' => 'Hosting', 'quantity' => '2', 'unit_price' => '119,00', 'tax_rate' => '19'],
            ['description' => 'Service', 'quantity' => '1.5', 'unit_price' => '10.70', 'tax_rate' => '7'],
        ], 'standard');
        self::assertSame(25405, $result['gross_cents']);
        self::assertSame(21500, $result['net_cents']);
        self::assertSame(3905, $result['tax_cents']);
        self::assertSame([700, 1900], array_column($result['tax_breakdown'], 'tax_rate_bps'));
        self::assertTrue($result['mixed_tax_rates']);
        self::assertSame(1500, $result['items'][1]['quantity_milli']);
    }

    public function testRoundingIsHalfUpPerLineAndZeroPricedItemsAreAllowed(): void
    {
        $row = ['description' => 'Tiny service', 'quantity' => '0.5', 'unit_price' => '0.01', 'tax_rate' => '19'];
        $zero = array_replace($row, ['description' => 'Included', 'unit_price' => '0']);
        $result = InvoiceItems::calculate([$row, $row, $zero], 'standard');
        self::assertSame(2, $result['gross_cents']);
        self::assertSame(0, $result['items'][2]['gross_cents']);
        self::assertSame($result['gross_cents'], $result['net_cents'] + $result['tax_cents']);
    }

    public function testExemptModesKeepGrossAndApplyZeroTax(): void
    {
        foreach (['exempt', 'reverse_charge', 'small_business'] as $mode) {
            $result = InvoiceItems::calculate([self::row()], $mode);
            self::assertSame(11900, $result['net_cents']);
            self::assertSame(0, $result['tax_cents']);
        }
    }

    public function testManyTinyLinesRoundVatPerRateWithoutChangingGrossPrices(): void
    {
        $rows = array_fill(0, 100, ['description' => 'Kleinposition', 'quantity' => '1', 'unit_price' => '0.03', 'tax_rate' => '19']);
        $result = InvoiceItems::calculate($rows, 'standard');
        self::assertSame(300, $result['gross_cents']);
        self::assertSame(252, $result['net_cents']);
        self::assertSame(48, $result['tax_cents']);
        self::assertSame(252, array_sum(array_column($result['items'], 'net_cents')));
        self::assertSame(48, array_sum(array_column($result['items'], 'tax_cents')));
        self::assertSame([3], array_values(array_unique(array_column($result['items'], 'gross_cents'))));
    }

    #[DataProvider('invalid')]
    public function testInvalidInputIsRejected(mixed $rows): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InvoiceItems::calculate($rows, 'standard');
    }

    public static function invalid(): iterable
    {
        yield [null];
        yield ['invalid'];
        yield [[]];
        yield [array_fill(0, 101, self::row())];
        foreach (['quantity' => ['0', '-1', '1e2', '1.0001', '1000000'], 'unit_price' => ['-1', '1e3', '21474836.48', '1.001'], 'tax_rate' => ['100.01', '-1'], 'description' => ['', "bad\0text", str_repeat('a', 2001)]] as $field => $values) {
            foreach ($values as $value) {
                yield [[array_replace(self::row(), [$field => $value])]];
            }
        }
        yield [[array_replace(self::row(), ['quantity' => []])]];
        yield [[array_replace(self::row(), ['unit_price' => '0'])]];
        yield [[array_replace(self::row(), ['quantity' => '2', 'unit_price' => '21474836.47'])]];
        yield [array_fill(0, 13, array_replace(self::row(), ['description' => str_repeat('a', 2000)]))];
    }

    private static function row(): array
    {
        return ['description' => 'Hosting', 'quantity' => '1', 'unit_price' => '119.00', 'tax_rate' => '19'];
    }
}
