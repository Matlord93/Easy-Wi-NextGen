<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Module\Core\Domain\Entity\ShopCoupon;
use PHPUnit\Framework\TestCase;

final class ShopCouponConfigurationTest extends TestCase
{
    public function testChangingAnOfferRetainsTheCodeAndRedemptionCount(): void
    {
        $coupon = new ShopCoupon(1, 'KEEP20', 'percent', 20, 0, 4);
        $now = new \DateTimeImmutable();
        $coupon->redeem($now);
        $coupon->redeem($now);
        $coupon->configure('fixed', 500, 1000, 3, null, null, true);
        self::assertSame('KEEP20', $coupon->getCode());
        self::assertSame(2, $coupon->getUsedCount());
        self::assertSame(500, $coupon->discountFor(2000, $now));
        $coupon->configure('fixed', 500, 1000, 2, null, null, true);
        self::assertFalse($coupon->isAvailable($now));
        self::assertSame(2, $coupon->getUsedCount());
    }

    public function testInvalidChangesDoNotMutateTheExistingCoupon(): void
    {
        $coupon = new ShopCoupon(1, 'KEEP20', 'percent', 20);
        try {
            $coupon->configure('percent', 101, 0, null, null, null, false);
            self::fail('An invalid discount must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame(20, $coupon->getDiscountValue());
            self::assertTrue($coupon->isActive());
        }
    }
}
