<?php

declare(strict_types=1);

namespace App\Tests\Module\PanelCustomer\Application;

use App\Module\PanelCustomer\Application\BookedResourceUsageAggregator;
use PHPUnit\Framework\TestCase;

final class BookedResourceUsageAggregatorTest extends TestCase
{
    public function testAggregateComputesBookedVsUsedTotals(): void
    {
        $aggregator = new BookedResourceUsageAggregator();

        $result = $aggregator->aggregate([
            [
                'booked_cpu_cores' => 2.0,
                'booked_ram_bytes' => 4 * 1024 * 1024 * 1024,
                'used_cpu_percent' => 50.0,
                'used_ram_bytes' => 2 * 1024 * 1024 * 1024,
            ],
            [
                'booked_cpu_cores' => 1.0,
                'booked_ram_bytes' => 2 * 1024 * 1024 * 1024,
                'used_cpu_percent' => 20.0,
                'used_ram_bytes' => 512 * 1024 * 1024,
            ],
        ]);

        self::assertSame(3.0, $result['total_booked_cpu_cores']);
        self::assertEqualsWithDelta(1.2, (float) $result['total_used_cpu_cores'], 0.0001);
        self::assertEqualsWithDelta(40.0, (float) $result['total_cpu_percent'], 0.0001);
        self::assertSame(6 * 1024 * 1024 * 1024, $result['total_booked_ram_bytes']);
        self::assertSame((int) (2.5 * 1024 * 1024 * 1024), $result['total_used_ram_bytes']);
        self::assertEqualsWithDelta(41.6667, (float) $result['total_ram_percent'], 0.01);
    }

    public function testAggregateReturnsUnknownWhenBookedCpuMissing(): void
    {
        $aggregator = new BookedResourceUsageAggregator();

        $result = $aggregator->aggregate([
            [
                'booked_cpu_cores' => null,
                'booked_ram_bytes' => 1024,
                'used_cpu_percent' => 90.0,
                'used_ram_bytes' => 100,
            ],
        ]);

        self::assertNull($result['total_booked_cpu_cores']);
        self::assertNull($result['total_used_cpu_cores']);
        self::assertNull($result['total_cpu_percent']);
        self::assertSame(1024, $result['total_booked_ram_bytes']);
        self::assertSame(100, $result['total_used_ram_bytes']);
        self::assertEqualsWithDelta(9.7656, (float) $result['total_ram_percent'], 0.01);
    }
}
