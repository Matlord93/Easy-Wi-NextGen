<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Monitoring;

use App\Infrastructure\Config\JsonArray;

/**
 * The same sufficient statistics are used for raw observations and hourly archives.
 * @psalm-type MetricSummary = array{count: int, average: float, min: float, max: float}
 * @psalm-type AggregatePoint = array{
 *     samples: int, first_observed_at: int, last_observed_at: int,
 *     metrics: array<string, MetricSummary>, sources: list<string>, memory_sources: list<string>,
 *     interval_min_seconds?: float, interval_max_seconds?: float,
 *     stream?: string, timestamp?: int, resolution_seconds?: int
 * }
 */
final class InfrastructureAggregation
{
    /** @return AggregatePoint */
    public static function empty(): array
    {
        return ['samples' => 0, 'first_observed_at' => 0, 'last_observed_at' => 0, 'metrics' => [], 'sources' => [], 'memory_sources' => []];
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return AggregatePoint
     */
    public static function sample(string $stream, int $observed, array $payload): array
    {
        $point = self::empty();
        if (!in_array($stream, ['load', 'io'], true)) {
            return $point;
        }
        foreach ('load' === $stream ? InfrastructureHistoryStore::LOAD : InfrastructureHistoryStore::IO as $field) {
            $value = self::measurement($payload[$field] ?? null);
            if ($value !== null) {
                $point['metrics'][$field] = ['count' => 1, 'average' => $value, 'min' => $value, 'max' => $value];
            }
        }
        if ([] === $point['metrics']) {
            return $point;
        }
        $point['samples'] = 1;
        $point['first_observed_at'] = $point['last_observed_at'] = $observed;
        if (in_array($payload['source'] ?? null, ['agent_heartbeat', 'proxmox_status', 'proxmox_cluster', 'counter_delta', 'proxmox_rrd'], true)) {
            $point['sources'][] = (string) $payload['source'];
        }
        if (in_array($payload['memory_source'] ?? null, ['host_rss', 'guest_balloon'], true)) {
            $point['memory_sources'][] = (string) $payload['memory_source'];
        }
        $interval = self::measurement($payload['interval_seconds'] ?? null);
        if ('io' === $stream && $interval !== null && $interval > 0.0 && $interval <= 600.0) {
            $point['interval_min_seconds'] = $point['interval_max_seconds'] = $interval;
        }

        return $point;
    }

    /** @return AggregatePoint */
    public static function fromJson(string $json): array
    {
        $data = JsonArray::fromValue(json_decode($json, true, 32, JSON_THROW_ON_ERROR));
        if ($data === null) {
            throw new \UnexpectedValueException('Invalid infrastructure aggregate.');
        }
        $point = self::empty();
        foreach (['samples', 'first_observed_at', 'last_observed_at'] as $field) {
            $point[$field] = self::nonnegativeInteger($data[$field] ?? null);
        }
        $metrics = JsonArray::fromValue($data['metrics'] ?? null);
        if ($metrics === null) {
            throw new \UnexpectedValueException('Invalid infrastructure aggregate metrics.');
        }
        foreach (array_keys($metrics) as $name) {
            if (!is_string($name) || !in_array($name, [...InfrastructureHistoryStore::LOAD, ...InfrastructureHistoryStore::IO], true)) {
                throw new \UnexpectedValueException('Invalid infrastructure metric name.');
            }
            $metric = JsonArray::fromValue($metrics[$name]);
            if ($metric === null) {
                throw new \UnexpectedValueException('Invalid infrastructure metric.');
            }
            $count = self::nonnegativeInteger($metric['count'] ?? null);
            if ($count === 0) {
                throw new \UnexpectedValueException('Infrastructure metric count must be positive.');
            }
            $point['metrics'][$name] = [
                'count' => $count,
                'average' => self::nonnegativeFloat($metric['average'] ?? null),
                'min' => self::nonnegativeFloat($metric['min'] ?? null),
                'max' => self::nonnegativeFloat($metric['max'] ?? null),
            ];
        }
        $point['sources'] = self::sourceList($data['sources'] ?? null, ['agent_heartbeat', 'proxmox_status', 'proxmox_cluster', 'counter_delta', 'proxmox_rrd']);
        $point['memory_sources'] = self::sourceList($data['memory_sources'] ?? null, ['host_rss', 'guest_balloon']);
        if (isset($data['interval_min_seconds']) || isset($data['interval_max_seconds'])) {
            $point['interval_min_seconds'] = self::nonnegativeFloat($data['interval_min_seconds'] ?? null);
            $point['interval_max_seconds'] = self::nonnegativeFloat($data['interval_max_seconds'] ?? null);
        }

        return $point;
    }

    private static function measurement(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) && $number >= 0.0 ? $number : null;
    }

    private static function nonnegativeInteger(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new \UnexpectedValueException('Invalid infrastructure aggregate count or timestamp.');
        }

        return $value;
    }

    private static function nonnegativeFloat(mixed $value): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
            throw new \UnexpectedValueException('Invalid infrastructure aggregate measurement.');
        }

        return (float) $value;
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function sourceList(mixed $value, array $allowed): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Invalid infrastructure aggregate sources.');
        }
        $sources = [];
        foreach ($value as $source) {
            if (!is_string($source) || !in_array($source, $allowed, true)) {
                throw new \UnexpectedValueException('Invalid infrastructure aggregate source.');
            }
            $sources[] = $source;
        }

        return $sources;
    }

    /**
     * @param AggregatePoint $left
     * @param AggregatePoint $right
     * @return AggregatePoint
     */
    public static function merge(array $left, array $right): array
    {
        if (0 === $right['samples']) {
            return $left;
        }
        if (0 === $left['samples']) {
            return $right;
        }
        $left['samples'] += $right['samples'];
        $left['first_observed_at'] = min($left['first_observed_at'], $right['first_observed_at']);
        $left['last_observed_at'] = max($left['last_observed_at'], $right['last_observed_at']);
        foreach ($right['metrics'] as $field => $next) {
            if (!isset($left['metrics'][$field])) {
                $left['metrics'][$field] = $next;
                continue;
            }
            $previous = $left['metrics'][$field];
            $count = $previous['count'] + $next['count'];
            // Do not multiply an unbounded measurement by a sample count before dividing.
            $left['metrics'][$field] = ['count' => $count, 'average' => $previous['average'] + ($next['average'] - $previous['average']) * ((float) $next['count'] / (float) $count), 'min' => min($previous['min'], $next['min']), 'max' => max($previous['max'], $next['max'])];
        }
        foreach (['sources', 'memory_sources'] as $field) {
            $left[$field] = array_values(array_unique([...$left[$field], ...$right[$field]]));
        }
        if (isset($right['interval_min_seconds'], $right['interval_max_seconds'])) {
            $left['interval_min_seconds'] = min($left['interval_min_seconds'] ?? $right['interval_min_seconds'], $right['interval_min_seconds']);
            $left['interval_max_seconds'] = max($left['interval_max_seconds'] ?? $right['interval_max_seconds'], $right['interval_max_seconds']);
        }

        return $left;
    }
}
