<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\ShopCoupon;

final class ShopPriceCalculator
{
    /** @return array{subtotal_cents: int, discount_cents: int, total_cents: int} */
    public function calculate(int $monthlyCents, int $months, ?ShopCoupon $coupon = null, ?\DateTimeImmutable $now = null): array
    {
        if ($monthlyCents < 0 || $months < 1 || $months > 120) {
            throw new \InvalidArgumentException('Invalid shop price or billing duration.');
        }
        $subtotal = $monthlyCents * $months;
        $discount = $coupon?->discountFor($subtotal, $now ?? new \DateTimeImmutable()) ?? 0;
        return ['subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'total_cents' => $subtotal - $discount];
    }
}
