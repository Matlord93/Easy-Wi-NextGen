<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

final class InvoiceItems
{
    public const MAX_ITEMS = 100;

    /** Quantities have three decimal places; all calculations use integers. */
    public static function calculate(mixed $rows, string $mode): array
    {
        if (!is_array($rows) || count($rows) < 1 || count($rows) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException('billing.invalid_items');
        }
        $items = $groups = [];
        $net = $tax = $gross = $descriptionBytes = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('billing.invalid_items');
            }
            foreach (['description', 'quantity', 'unit_price', 'tax_rate'] as $key) {
                if (!is_string($row[$key] ?? null)) {
                    throw new \InvalidArgumentException('billing.invalid_items');
                }
            }
            $description = trim($row['description']);
            $descriptionBytes += strlen($description);
            if ('' === $description || mb_strlen($description) > 2000 || $descriptionBytes > 24000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $description)) {
                throw new \InvalidArgumentException('billing.invalid_description');
            }
            $quantity = self::quantity($row['quantity']);
            $price = BillingAmount::parse($row['unit_price']);
            $lineGross = intdiv($price * $quantity + 500, 1000);
            if ($lineGross > 2147483647 || $gross + $lineGross > 2147483647) {
                throw new \InvalidArgumentException('billing.invalid_amount');
            }
            $line = BillingAmount::splitGross(max(1, $lineGross), $mode, BillingAmount::parse($row['tax_rate']));
            if (0 === $lineGross) {
                $line['net_cents'] = $line['tax_cents'] = $line['gross_cents'] = 0;
            }
            $items[] = $line + ['description' => $description, 'quantity_milli' => $quantity, 'unit_price_cents' => $price];
            $rate = $line['tax_rate_bps'];
            $groups[$rate] ??= ['tax_rate_bps' => $rate, 'net_cents' => 0, 'tax_cents' => 0, 'gross_cents' => 0];
            foreach (['net_cents', 'tax_cents', 'gross_cents'] as $field) {
                $groups[$rate][$field] += $line[$field];
            }
            $net += $line['net_cents'];
            $tax += $line['tax_cents'];
            $gross += $lineGross;
        }
        if (0 === $gross) {
            throw new \InvalidArgumentException('billing.invalid_amount');
        }
        // VAT is rounded once per rate, as required by EN16931. Distribute the
        // cent difference over nonzero lines while preserving every gross price.
        // Previously issued invoices keep their original immutable snapshot.
        $net = $tax = 0;
        foreach ($groups as $rate => &$group) {
            if (0 === $group['gross_cents']) {
                continue;
            }
            $rounded = BillingAmount::splitGross($group['gross_cents'], $mode, $rate);
            $difference = $rounded['net_cents'] - $group['net_cents'];
            foreach ($items as &$item) {
                if (0 === $difference) {
                    break;
                }
                if ($item['tax_rate_bps'] !== $rate || 0 === $item['gross_cents']) {
                    continue;
                }
                $step = $difference > 0 ? 1 : -1;
                if ($item['net_cents'] + $step < 0 || $item['net_cents'] + $step > $item['gross_cents']) {
                    continue;
                }
                $item['net_cents'] += $step;
                $item['tax_cents'] -= $step;
                $difference -= $step;
            }
            unset($item);
            if (0 !== $difference) {
                throw new \LogicException('Unable to allocate invoice tax rounding.');
            }
            foreach (['net_cents', 'tax_cents', 'gross_cents'] as $field) {
                $group[$field] = $rounded[$field];
            }
            $net += $group['net_cents'];
            $tax += $group['tax_cents'];
        }
        unset($group);
        ksort($groups);

        return ['items' => $items, 'tax_breakdown' => array_values($groups), 'tax_mode' => $mode,
            'tax_rate_bps' => 1 === count($groups) ? array_key_first($groups) : 0, 'mixed_tax_rates' => count($groups) > 1,
            'net_cents' => $net, 'tax_cents' => $tax, 'gross_cents' => $gross, 'pricing_basis' => 'gross', 'rounding_basis' => 'tax_group'];
    }

    public static function quantity(string $raw): int
    {
        if (!preg_match('/^(\d{1,6})(?:[.,](\d{1,3}))?$/D', trim($raw), $match)) {
            throw new \InvalidArgumentException('billing.invalid_quantity');
        }
        $value = (int) $match[1] * 1000 + (int) str_pad($match[2] ?? '', 3, '0');
        if ($value < 1) {
            throw new \InvalidArgumentException('billing.invalid_quantity');
        }

        return $value;
    }

    /** Old invoices retain their original totals and appear as one position. */
    public static function forInvoice(\App\Module\Core\Domain\Entity\Invoice $invoice): array
    {
        $details = $invoice->getBillingDetails();

        return $details['items'] ?? [['description' => $details['description'] ?? $invoice->getNumber(), 'quantity_milli' => 1000,
            'unit_price_cents' => $invoice->getAmountTotalCents(), 'net_cents' => $details['net_cents'] ?? $invoice->getAmountTotalCents(),
            'tax_cents' => $details['tax_cents'] ?? 0, 'gross_cents' => $invoice->getAmountTotalCents(), 'tax_rate_bps' => $details['tax_rate_bps'] ?? 0]];
    }
}
