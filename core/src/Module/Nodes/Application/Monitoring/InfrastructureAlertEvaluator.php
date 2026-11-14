<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Monitoring;

use App\Infrastructure\Config\JsonArray;

/**
 * Pure state machine: stale/absent metrics are never interpreted as zero load.
 * @psalm-type MetricState = array{since: int|null, active: bool, last: int, unknown?: bool}
 * @psalm-type MetricStates = array<string, MetricState>
 * @psalm-type AlertEvent = array{metric: string, active: bool, episode: int}
 * @psalm-type AlertThresholds = array{cpu_percent: int, memory_percent: int, disk_percent: int,
 *     hold_seconds: int, offline_seconds: int, ...<string, int|bool>}
 */
final class InfrastructureAlertEvaluator
{
    /**
     * @param array<array-key, mixed> $state
     * @param array<array-key, mixed> $values
     * @param AlertThresholds $thresholds
     * @return array{state: MetricStates, events: list<AlertEvent>}
     */
    public static function evaluate(array $state, array $values, array $thresholds, int $observed, bool $available): array
    {
        $state = self::normalizeState($state);
        $events = [];
        foreach (['cpu_percent', 'memory_percent', 'disk_percent', 'offline'] as $metric) {
            $old = $state[$metric] ?? ['since' => null, 'active' => false, 'last' => 0];
            if ($observed <= $old['last']) {
                continue;
            }
            $value = self::measurement($values[$metric] ?? null);
            if ($metric !== 'offline' && (!$available || $value === null)) {
                $state[$metric] = $old + ['unknown' => true];
                $state[$metric]['since'] = null;
                continue;
            }
            $high = $metric === 'offline' ? !$available : $value !== null && $value >= (float) $thresholds[$metric];
            $hold = $metric === 'offline' ? $thresholds['offline_seconds'] : $thresholds['hold_seconds'];
            $next = $old;
            if ($high) {
                $continuous = $metric === 'offline' || $observed - $old['last'] <= 150;
                $next['since'] = $continuous && $old['since'] !== null ? $old['since'] : $observed;
                if (!$old['active'] && $observed - $next['since'] >= $hold) {
                    $next['active'] = true;
                    $events[] = ['metric' => $metric, 'active' => true, 'episode' => $next['since']];
                }
            } elseif ($metric === 'offline' || !$old['active'] || ($value !== null && $value < max(0.0, (float) $thresholds[$metric] - 5.0))) {
                $next['since'] = null;
                if ($old['active']) {
                    $next['active'] = false;
                    $events[] = ['metric' => $metric, 'active' => false, 'episode' => $old['since'] ?? $observed];
                }
            }
            $next['last'] = $observed;
            unset($next['unknown']);
            $state[$metric] = $next;
        }

        return ['state' => $state, 'events' => $events];
    }

    /** @return MetricStates */
    public static function normalizeState(mixed $input): array
    {
        $raw = JsonArray::orEmpty($input);
        $state = [];
        foreach (['cpu_percent', 'memory_percent', 'disk_percent', 'offline'] as $metric) {
            $entry = JsonArray::fromValue($raw[$metric] ?? null);
            if ($entry === null) {
                continue;
            }
            $value = [
                'since' => self::timestamp($entry['since'] ?? null),
                'active' => filter_var($entry['active'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'last' => self::timestamp($entry['last'] ?? null) ?? 0,
            ];
            if (($entry['unknown'] ?? false) === true) {
                $value['unknown'] = true;
            }
            $state[$metric] = $value;
        }

        return $state;
    }

    private static function timestamp(mixed $value): ?int
    {
        $time = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($time) && $time >= 0 ? $time : null;
    }

    private static function measurement(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }
}
