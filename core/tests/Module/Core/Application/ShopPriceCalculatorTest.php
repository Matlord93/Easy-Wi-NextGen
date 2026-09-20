<?php
declare(strict_types=1);
namespace App\Tests\Module\Core\Application;
use App\Module\Core\Application\ShopPriceCalculator;
use App\Module\Core\Domain\Entity\ShopCoupon;
use PHPUnit\Framework\TestCase;
final class ShopPriceCalculatorTest extends TestCase
{
    public function testAppliesPercentageCoupon(): void
    {
        $coupon = new ShopCoupon(1, 'SAVE20', 'percent', 20);
        self::assertSame(['subtotal_cents' => 3000, 'discount_cents' => 600, 'total_cents' => 2400], (new ShopPriceCalculator())->calculate(1000, 3, $coupon, new \DateTimeImmutable()));
    }
    public function testFixedCouponNeverMakesTotalNegative(): void
    {
        $coupon = new ShopCoupon(1, 'FREE100', 'fixed', 10000);
        self::assertSame(500, (new ShopPriceCalculator())->calculate(500, 1, $coupon)['discount_cents']);
    }
}
