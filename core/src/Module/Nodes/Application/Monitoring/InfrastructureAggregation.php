<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Monitoring;

/** The same sufficient statistics are used for raw observations and hourly archives. */
final class InfrastructureAggregation
{
    public static function empty(): array
    {
        return ['samples' => 0, 'first_observed_at' => 0, 'last_observed_at' => 0, 'metrics' => [], 'sources' => [], 'memory_sources' => []];
    }

    public static function sample(string $stream, int $observed, array $payload): array
    {
        $point = self::empty();
        if (!in_array($stream, ['load', 'io'], true)) {
            return $point;
        }
        foreach ('load' === $stream ? InfrastructureHistoryStore::LOAD : InfrastructureHistoryStore::IO as $field) {
            $value = $payload[$field] ?? null;
            if (is_numeric($value) && is_finite((float) $value) && $value >= 0) {
                $point['metrics'][$field] = ['count' => 1, 'average' => (float) $value, 'min' => (float) $value, 'max' => (float) $value];
            }
        }
        if ([] === $point['metrics']) {
            return $point;
        }
        $point['samples'] = 1;
        $point['first_observed_at'] = $point['last_observed_at'] = $observed;
        if (in_array($payload['source'] ?? null, ['agent_heartbeat', 'proxmox_status', 'proxmox_cluster', 'counter_delta', 'proxmox_rrd'], true)) {
            $point['sources'][] = $payload['source'];
        }
        if (in_array($payload['memory_source'] ?? null, ['host_rss', 'guest_balloon'], true)) {
            $point['memory_sources'][] = $payload['memory_source'];
        }
        $interval = $payload['interval_seconds'] ?? null;
        if ('io' === $stream && is_numeric($interval) && is_finite((float) $interval) && $interval > 0 && $interval <= 600) {
            $point['interval_min_seconds'] = $point['interval_max_seconds'] = (float) $interval;
        }

        return $point;
    }

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
            $left['metrics'][$field] = ['count' => $count, 'average' => $previous['average'] + ($next['average'] - $previous['average']) * ($next['count'] / $count), 'min' => min($previous['min'], $next['min']), 'max' => max($previous['max'], $next['max'])];
        }
        foreach (['sources', 'memory_sources'] as $field) {
            $left[$field] = array_values(array_unique([...$left[$field], ...$right[$field]]));
        }
        if (isset($right['interval_min_seconds'])) {
            $left['interval_min_seconds'] = min($left['interval_min_seconds'] ?? $right['interval_min_seconds'], $right['interval_min_seconds']);
            $left['interval_max_seconds'] = max($left['interval_max_seconds'] ?? $right['interval_max_seconds'], $right['interval_max_seconds']);
        }

        return $left;
    }
}
