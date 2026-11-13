<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Monitoring;

/** Pure state machine: stale/absent metrics are never interpreted as zero load. */
final class InfrastructureAlertEvaluator
{
    public static function evaluate(array $state, array $values, array $thresholds, int $observed, bool $available): array
    {
        $events = [];
        foreach (['cpu_percent', 'memory_percent', 'disk_percent', 'offline'] as $metric) {
            $old = $state[$metric] ?? ['since' => null, 'active' => false, 'last' => 0];
            if ($observed <= $old['last']) {
                continue;
            }
            $value = $values[$metric] ?? null;
            $known = 'offline' === $metric || ($available && is_numeric($value) && is_finite((float) $value));
            if (!$known) {
                $state[$metric] = $old + ['unknown' => true];
                $state[$metric]['since'] = null;
                continue;
            }
            $high = 'offline' === $metric ? !$available : $value >= $thresholds[$metric];
            $hold = 'offline' === $metric ? $thresholds['offline_seconds'] : $thresholds['hold_seconds'];
            $next = $old;
            if ($high) {
                $continuous = 'offline' === $metric || $observed - $old['last'] <= 150;
                $next['since'] = $continuous && null !== $old['since'] ? $old['since'] : $observed;
                if (!$old['active'] && $observed - $next['since'] >= $hold) {
                    $next['active'] = true;
                    $events[] = ['metric' => $metric, 'active' => true, 'episode' => $next['since']];
                }
            } elseif ('offline' === $metric || !$old['active'] || $value < max(0, $thresholds[$metric] - 5)) {
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
}
