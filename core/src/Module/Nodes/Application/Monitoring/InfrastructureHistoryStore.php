<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Monitoring;

use App\Module\Nodes\Application\InfrastructureUsage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Minute samples are independent of browser sessions. Never substitute missing values. */
final readonly class InfrastructureHistoryStore
{
    public const LOAD = ['cpu_percent', 'memory_percent', 'disk_percent', 'memory_used_bytes', 'memory_total_bytes', 'disk_used_bytes', 'disk_total_bytes'];
    public const IO = ['net_rx_bytes_per_second', 'net_tx_bytes_per_second', 'disk_read_bytes_per_second', 'disk_write_bytes_per_second'];

    public function __construct(private Connection $db, private InfrastructureUsage $usage)
    {
    }

    public function record(InfrastructureTarget $target, array $input): int
    {
        $data = $this->usage->normalize($input);
        $written = 0;
        foreach (['load' => self::LOAD, 'io' => self::IO] as $stream => $fields) {
            $timestamp = 'load' === $stream ? $data['observed_at'] : $data['io_observed_at'];
            $stale = 'load' === $stream ? $data['stale'] : $data['io_stale'];
            if (!$timestamp || $stale) {
                continue;
            }
            $values = array_intersect_key($data, array_flip($fields));
            if (!array_filter($values, static fn ($v): bool => null !== $v)) {
                continue;
            }
            $values['source'] = 'load' === $stream ? $data['load_source'] : $data['io_source'];
            $values['memory_source'] = 'load' === $stream ? $data['memory_source'] : null;
            $values['interval_seconds'] = 'io' === $stream ? $data['io_interval_seconds'] : null;
            $observed = (new \DateTimeImmutable($timestamp))->getTimestamp();
            if (null !== $target->visibleSince && ($observed <= $target->visibleSince || ('io' === $stream && $observed - ($data['io_interval_seconds'] ?? 0) <= $target->visibleSince))) {
                continue;
            }
            try {
                $this->db->insert('infrastructure_samples', ['resource_key' => $target->key, 'stream' => $stream, 'bucket' => intdiv($observed, 60) * 60, 'observed_at' => $observed, 'payload' => json_encode($values, JSON_THROW_ON_ERROR)]);
                ++$written;
            } catch (UniqueConstraintViolationException) {
                // First real observation per provider-minute wins. Repeated RRD/heartbeat data is not duplicated.
            }
        }

        return $written;
    }

    /** At most 1200 aggregate points, even for a year-long request. */
    public function history(InfrastructureTarget $target, int $from, int $to): array
    {
        if ($from < 0 || $to <= $from || $to - $from > 366 * 86400 || $to > time() + 30) {
            throw new \InvalidArgumentException('monitor.invalid_range');
        }
        $resolution = max(60, (int) ceil(($to - $from) / 600 / 60) * 60);
        $groups = [];
        $rows = $this->db->iterateAssociative('SELECT stream, bucket, observed_at, payload FROM infrastructure_samples WHERE resource_key = ? AND bucket >= ? AND bucket < ? AND observed_at >= ? AND observed_at < ? ORDER BY bucket, stream', [$target->key, intdiv($from, 60) * 60, (intdiv($to, 60) + 1) * 60, max($from, ($target->visibleSince ?? -1) + 1), $to]);
        foreach ($rows as $row) {
            $stream = $row['stream'];
            if (!in_array($stream, ['load', 'io'], true)) {
                continue;
            }
            $bucket = intdiv((int) $row['observed_at'] - $from, $resolution) * $resolution + $from;
            $id = $stream.':'.$bucket;
            $groups[$id] ??= ['stream' => $stream, 'timestamp' => $bucket, 'first_observed_at' => (int) $row['observed_at'], 'last_observed_at' => (int) $row['observed_at'], 'samples' => 0, 'resolution_seconds' => $resolution, 'metrics' => [], 'sources' => [], 'memory_sources' => []];
            $group = &$groups[$id];
            ++$group['samples'];
            $group['first_observed_at'] = min($group['first_observed_at'], (int) $row['observed_at']);
            $group['last_observed_at'] = max($group['last_observed_at'], (int) $row['observed_at']);
            $payload = json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR);
            $interval = $payload['interval_seconds'] ?? null;
            if ('io' === $stream && is_numeric($interval) && is_finite((float) $interval) && $interval > 0 && $interval <= 600) {
                $group['interval_min_seconds'] = min($group['interval_min_seconds'] ?? $interval, $interval);
                $group['interval_max_seconds'] = max($group['interval_max_seconds'] ?? $interval, $interval);
            }
            foreach ('load' === $stream ? self::LOAD : self::IO as $field) {
                $value = $payload[$field] ?? null;
                if (!is_numeric($value) || !is_finite((float) $value) || $value < 0) {
                    continue;
                }
                $v = (float) $value;
                $metric = $group['metrics'][$field] ?? ['count' => 0, 'average' => $v, 'min' => $v, 'max' => $v];
                ++$metric['count'];
                $metric['average'] += ($v - $metric['average']) / $metric['count'];
                $metric['min'] = min($metric['min'], $v);
                $metric['max'] = max($metric['max'], $v);
                $group['metrics'][$field] = $metric;
            }
            foreach (['source' => 'sources', 'memory_source' => 'memory_sources'] as $field => $destination) {
                if (is_string($payload[$field] ?? null) && in_array($payload[$field], ['agent_heartbeat', 'proxmox_status', 'proxmox_cluster', 'counter_delta', 'proxmox_rrd', 'host_rss', 'guest_balloon'], true)) {
                    $group[$destination][$payload[$field]] = true;
                }
            }
            unset($group);
        }
        foreach ($groups as &$group) {
            $group['sources'] = array_keys($group['sources']);
            $group['memory_sources'] = array_keys($group['memory_sources']);
        } unset($group);

        return ['from' => $from, 'to' => $to, 'resolution_seconds' => $resolution, 'points' => array_values($groups)];
    }

    public function prune(int $days, ?\DateTimeImmutable $now = null): int
    {
        if ($days < 1 || $days > 365) {
            throw new \InvalidArgumentException('monitor.invalid_retention');
        }
        $cutoff = ($now ?? new \DateTimeImmutable())->getTimestamp() - $days * 86400;

        return $this->db->executeStatement('DELETE FROM infrastructure_samples WHERE (resource_key, stream, bucket) IN (SELECT resource_key, stream, bucket FROM (SELECT resource_key, stream, bucket FROM infrastructure_samples WHERE bucket < ? ORDER BY bucket LIMIT 10000) expired_samples)', [$cutoff]);
    }
}
