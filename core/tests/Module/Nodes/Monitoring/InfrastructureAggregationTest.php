<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Monitoring;

use App\Module\Nodes\Application\Monitoring\InfrastructureAggregation as Aggregation;
use App\Module\Nodes\Application\Monitoring\InfrastructureHistoryStore;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testArchivedJsonPreservesKnownZeroAndWeightedStatistics(): void
    {
        $point = Aggregation::merge(
            Aggregation::sample('load', 100, ['cpu_percent' => 0, 'source' => 'agent_heartbeat']),
            Aggregation::sample('load', 200, ['cpu_percent' => 40, 'memory_percent' => 10, 'source' => 'proxmox_status']),
        );
        $restored = Aggregation::fromJson(json_encode($point, JSON_THROW_ON_ERROR));

        self::assertSame($point, $restored);
        self::assertSame(0.0, $restored['metrics']['cpu_percent']['min']);
        self::assertSame(20.0, $restored['metrics']['cpu_percent']['average']);
    }

    public function testMergingKeepsIndependentOrderedSourcesAndLeftHandMetadata(): void
    {
        $first = Aggregation::sample('load', 100, ['cpu_percent' => 0, 'source' => 'agent_heartbeat', 'memory_source' => 'host_rss']);
        $first += ['stream' => 'load', 'timestamp' => 60, 'resolution_seconds' => 60];
        $second = Aggregation::sample('load', 160, ['cpu_percent' => 30, 'source' => 'proxmox_status', 'memory_source' => 'guest_balloon']);
        $third = Aggregation::sample('load', 220, ['cpu_percent' => 60, 'source' => 'agent_heartbeat', 'memory_source' => 'host_rss']);

        $result = Aggregation::merge(Aggregation::merge($first, $second), $third);

        self::assertSame(['agent_heartbeat', 'proxmox_status'], $result['sources']);
        self::assertSame(['host_rss', 'guest_balloon'], $result['memory_sources']);
        self::assertSame('load', $result['stream']);
        self::assertSame(60, $result['timestamp']);
        self::assertSame(60, $result['resolution_seconds']);
        self::assertSame(['agent_heartbeat'], $first['sources']);
        self::assertSame(['host_rss'], $first['memory_sources']);
    }

    public function testIoArchiveRetainsCountersIntervalsAndKnownZero(): void
    {
        $point = Aggregation::merge(
            Aggregation::sample('io', 100, ['net_rx_bytes_per_second' => 0, 'source' => 'counter_delta', 'interval_seconds' => 5]),
            Aggregation::sample('io', 160, ['net_rx_bytes_per_second' => 100, 'source' => 'proxmox_rrd', 'interval_seconds' => 10]),
        );
        $restored = Aggregation::fromJson(json_encode($point, JSON_THROW_ON_ERROR));
        // JSON object member order does not affect the archived statistics.
        ksort($point);
        ksort($restored);

        self::assertSame($point, $restored);
        self::assertSame(2, $restored['samples']);
        self::assertSame(100, $restored['first_observed_at']);
        self::assertSame(160, $restored['last_observed_at']);
        self::assertSame(0.0, $restored['metrics']['net_rx_bytes_per_second']['min']);
        self::assertSame(50.0, $restored['metrics']['net_rx_bytes_per_second']['average']);
        self::assertSame(5.0, $restored['interval_min_seconds']);
        self::assertSame(10.0, $restored['interval_max_seconds']);
    }

    public function testMalformedArchivedMetricIsRejectedInsteadOfBecomingZero(): void
    {
        $point = Aggregation::sample('load', 100, ['cpu_percent' => 30]);
        $point['metrics']['cpu_percent']['average'] = 'not a measurement';
        $this->expectException(\UnexpectedValueException::class);

        Aggregation::fromJson(json_encode($point, JSON_THROW_ON_ERROR));
    }

    public function testArchivedUnknownSourcesAreRejected(): void
    {
        $point = Aggregation::sample('load', 100, ['cpu_percent' => 30]);
        $point['sources'] = ['credential-bearing-secret'];
        $this->expectException(\UnexpectedValueException::class);

        Aggregation::fromJson(json_encode($point, JSON_THROW_ON_ERROR));
    }

    public function testAllSupportedLoadAndIoMetricsSurviveArchiving(): void
    {
        $point = Aggregation::merge(
            Aggregation::sample('load', 100, array_fill_keys(InfrastructureHistoryStore::LOAD, 0)),
            Aggregation::sample('io', 160, array_fill_keys(InfrastructureHistoryStore::IO, 0)),
        );

        $restored = Aggregation::fromJson(json_encode($point, JSON_THROW_ON_ERROR));

        self::assertSame($point, $restored);
        self::assertCount(11, $restored['metrics']);
    }

    #[DataProvider('invalidMetricNames')]
    public function testArchivedMetricNamesOutsideTheWhitelistAreRejected(int|string $name): void
    {
        $point = Aggregation::sample('load', 100, ['cpu_percent' => 30]);
        $point['metrics'] = [$name => $point['metrics']['cpu_percent']];
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid infrastructure metric name.');

        Aggregation::fromJson(json_encode($point, JSON_THROW_ON_ERROR));
    }

    public static function invalidMetricNames(): iterable
    {
        yield 'unsupported string' => ['unknown_metric'];
        yield 'numeric key' => [0];
    }
}
