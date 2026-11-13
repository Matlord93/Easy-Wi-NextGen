<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Monitoring;

use App\Module\Nodes\Application\Monitoring\InfrastructureAggregation as Aggregation;
use PHPUnit\Framework\TestCase;

final class InfrastructureAggregationTest extends TestCase
{
    public function testWeightedMeansUseMetricCountsAndKeepUnknownValuesUnknown(): void
    {
        $first = Aggregation::merge(Aggregation::sample('load', 100, ['cpu_percent' => 0]), Aggregation::sample('load', 200, ['cpu_percent' => 30]));
        $result = Aggregation::merge($first, Aggregation::sample('load', 300, ['cpu_percent' => 90, 'memory_percent' => 70]));
        self::assertSame(3, $result['samples']);
        self::assertSame(40.0, $result['metrics']['cpu_percent']['average']);
        self::assertSame(0.0, $result['metrics']['cpu_percent']['min']);
        self::assertSame(90.0, $result['metrics']['cpu_percent']['max']);
        self::assertSame(1, $result['metrics']['memory_percent']['count']);
        self::assertSame(70.0, $result['metrics']['memory_percent']['average']);
        self::assertSame(100, $result['first_observed_at']);
        self::assertSame(300, $result['last_observed_at']);
    }

    public function testInvalidValuesAndSourcesAreExcludedAndLargeFiniteValuesDoNotOverflow(): void
    {
        self::assertSame(Aggregation::empty(), Aggregation::sample('invalid', 1, ['cpu_percent' => 10]));
        self::assertSame(Aggregation::empty(), Aggregation::sample('load', 1, ['cpu_percent' => INF, 'memory_percent' => -1, 'disk_percent' => NAN]));
        $sample = Aggregation::sample('io', 1, ['net_rx_bytes_per_second' => 1e308, 'source' => 'secret', 'memory_source' => 'secret', 'interval_seconds' => 1000]);
        $merged = Aggregation::merge($sample, Aggregation::merge($sample, $sample));
        self::assertSame(1e308, $merged['metrics']['net_rx_bytes_per_second']['average']);
        self::assertSame([], $merged['sources']);
        self::assertSame([], $merged['memory_sources']);
        self::assertArrayNotHasKey('interval_max_seconds', $merged);
    }
}
