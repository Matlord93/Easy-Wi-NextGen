<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Monitoring;

/**
 * @psalm-import-type AggregatePoint from InfrastructureAggregation
 * @psalm-type HistoryPoint = array{
 *     samples: int, first_observed_at: int, last_observed_at: int,
 *     metrics: array<string, array{count: int, average: float, min: float, max: float}>,
 *     sources: list<string>, memory_sources: list<string>,
 *     interval_min_seconds?: float, interval_max_seconds?: float,
 *     stream: string, timestamp: int, resolution_seconds: int
 * }
 */
final class InfrastructureHistorySeries
{
    /** @var array<string, HistoryPoint> */
    private array $groups = [];

    public function __construct(
        private readonly int $from,
        private readonly int $resolution,
        private readonly bool $long,
    ) {
    }

    /** @param AggregatePoint $point */
    public function append(string $stream, array $point): void
    {
        if ($point['samples'] === 0) {
            return;
        }
        $observed = $point['first_observed_at'];
        $bucket = $this->long
            ? max($this->from, intdiv($observed, $this->resolution) * $this->resolution)
            : intdiv($observed - $this->from, $this->resolution) * $this->resolution + $this->from;
        $id = $stream.':'.$bucket;
        $this->groups[$id] = InfrastructureAggregation::merge($this->groups[$id] ?? InfrastructureAggregation::empty(), $point)
            + ['stream' => $stream, 'timestamp' => $bucket, 'resolution_seconds' => $this->resolution];
    }

    /** @return list<HistoryPoint> */
    public function points(): array
    {
        $points = array_values($this->groups);
        usort($points, static fn (array $a, array $b): int => [$a['timestamp'], $a['stream']] <=> [$b['timestamp'], $b['stream']]);

        return $points;
    }
}
