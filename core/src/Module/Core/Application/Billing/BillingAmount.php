<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

final class BillingAmount
{
    public static function parse(string $value): int
    {
        $value = trim(str_replace(',', '.', $value));
        if (!preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/D', $value, $matches)) {
            throw new \InvalidArgumentException('billing.invalid_amount');
        }
        $cents = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($cents > 2_147_483_647) {
            throw new \InvalidArgumentException('billing.invalid_amount');
        }

        return $cents;
    }

    /** @return array{tax_mode: string, tax_rate_bps: int, net_cents: int, tax_cents: int, gross_cents: int} */
    public static function splitGross(int $gross, string $mode, int $rateBps): array
    {
        if ($gross <= 0 || $gross > 2_147_483_647 || !in_array($mode, ['standard', 'exempt', 'reverse_charge', 'small_business'], true) || $rateBps < 0 || $rateBps > 10000) {
            throw new \InvalidArgumentException('billing.invalid_tax');
        }
        $rateBps = $mode === 'standard' ? $rateBps : 0;
        $divisor = 10000 + $rateBps;
        $net = intdiv($gross * 10000 + intdiv($divisor, 2), $divisor);

        return ['tax_mode' => $mode, 'tax_rate_bps' => $rateBps, 'net_cents' => $net, 'tax_cents' => $gross - $net, 'gross_cents' => $gross];
    }
}
