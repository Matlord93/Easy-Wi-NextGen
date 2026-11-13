<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Billing;

use App\Module\Core\Application\Billing\BillingAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillingAmountTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function testParsesDecimalAmountsWithoutFloatRounding(string $input, int $cents): void
    {
        self::assertSame($cents, BillingAmount::parse($input));
    }

    public static function validAmounts(): array
    {
        return [['0', 0], ['0.01', 1], ['19,90', 1990], [' 119.00 ', 11900], ['21474836.47', 2147483647]];
    }

    #[DataProvider('invalidAmounts')]
    public function testRejectsAmbiguousOrOutOfRangeAmounts(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BillingAmount::parse($input);
    }

    public static function invalidAmounts(): array
    {
        return [[''], ['-1'], ['1e3'], ['1.999'], ['1,000.00'], ['21474836.48'], ['INF']];
    }

    public function testGrossIncludesVatAndExemptModesDoNotAddIt(): void
    {
        $tax = BillingAmount::splitGross(11900, 'standard', 1900);
        self::assertSame(10000, $tax['net_cents']);
        self::assertSame(1900, $tax['tax_cents']);
        foreach (['exempt', 'reverse_charge', 'small_business'] as $mode) {
            $tax = BillingAmount::splitGross(11900, $mode, 1900);
            self::assertSame(11900, $tax['net_cents']);
            self::assertSame(0, $tax['tax_cents']);
        }
        self::assertSame(1, array_sum(array_intersect_key(BillingAmount::splitGross(1, 'standard', 1900), ['net_cents' => true, 'tax_cents' => true])));
    }
}
