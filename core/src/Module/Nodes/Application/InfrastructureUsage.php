<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;

/**
 * A numeric, credential-free view of provider metrics. Missing values stay unknown.
 * @psalm-type NormalizedUsage = array{
 *     cpu_percent: float|null,
 *     memory_used_bytes: float|null,
 *     memory_total_bytes: float|null,
 *     disk_used_bytes: float|null,
 *     disk_total_bytes: float|null,
 *     disk_read_bytes: float|null,
 *     disk_write_bytes: float|null,
 *     net_rx_bytes: float|null,
 *     net_tx_bytes: float|null,
 *     memory_percent: float|null,
 *     disk_percent: float|null,
 *     io_interval_seconds: float|null,
 *     net_rx_bytes_per_second: float|null,
 *     net_tx_bytes_per_second: float|null,
 *     disk_read_bytes_per_second: float|null,
 *     disk_write_bytes_per_second: float|null,
 *     memory_source: string|null,
 *     load_source: string|null,
 *     io_source: string|null,
 *     observed_at: string|null,
 *     io_observed_at: string|null,
 *     stale: bool,
 *     io_stale: bool,
 *     series_key: string,
 * }
 */
final class InfrastructureUsage
{
    /** @return NormalizedUsage */
    public function node(Agent $node): array
    {
        $m = JsonArray::orEmpty($node->getLastHeartbeatStats()['metrics'] ?? null);
        $cpu = JsonArray::orEmpty($m['cpu'] ?? null);
        $memory = JsonArray::orEmpty($m['memory'] ?? null);
        $disk = JsonArray::orEmpty($m['disk'] ?? null);

        return $this->normalize([
            'series_key' => hash('sha256', 'node:'.$node->getId()),
            'load_source' => 'agent_heartbeat',
            'cpu_percent' => $cpu['percent'] ?? null,
            'memory_used_bytes' => $memory['used'] ?? null,
            'memory_total_bytes' => $memory['total'] ?? null,
            'disk_used_bytes' => $disk['used'] ?? null,
            'disk_total_bytes' => $disk['total'] ?? null,
            'observed_at' => $m['collected_at'] ?? $node->getLastHeartbeatAt()?->format(DATE_ATOM),
        ]);
    }

    /** @return NormalizedUsage */
    public function kvm(Agent $node, string $uuid): array
    {
        $snapshot = JsonArray::orEmpty($node->getLastHeartbeatStats()['kvm_usage'] ?? null);
        $guests = JsonArray::orEmpty($snapshot['guests'] ?? null);
        $m = JsonArray::orEmpty($guests[$uuid] ?? null);
        $m['observed_at'] = $this->timestamp($snapshot['observed_at'] ?? null);
        $m['series_key'] = hash('sha256', 'kvm:'.$node->getId().':'.$uuid);
        $m['load_source'] = 'agent_heartbeat';

        return $this->normalize($m);
    }

    /** @return NormalizedUsage */
    public function proxmox(array $m): array
    {
        return $this->normalize([
            'series_key' => hash('sha256', 'pve:'.self::identifier($m['node'] ?? '').':'.self::identifier($m['vmid'] ?? 'node')),
            'load_source' => isset($m['vmid']) ? 'proxmox_status' : 'proxmox_cluster',
            'cpu_percent' => isset($m['cpu']) && is_numeric($m['cpu']) ? (float) $m['cpu'] * 100.0 : null,
            'memory_used_bytes' => $m['mem'] ?? null,
            'memory_total_bytes' => $m['maxmem'] ?? null,
            // QEMU's status/cluster `disk` field is not guest filesystem usage.
            // Nodes and LXC root filesystems provide an actual used-byte value.
            'disk_used_bytes' => !isset($m['vmid']) || 'lxc' === ($m['type'] ?? null) ? ($m['disk'] ?? null) : null,
            'disk_total_bytes' => $m['maxdisk'] ?? null,
            'observed_at' => $m['observed_at'] ?? (new \DateTimeImmutable())->format(DATE_ATOM),
            ...array_intersect_key($m, array_flip(['net_rx_bytes_per_second', 'net_tx_bytes_per_second', 'disk_read_bytes_per_second', 'disk_write_bytes_per_second', 'io_observed_at', 'io_interval_seconds', 'io_source'])),
        ]);
    }

    /** @return NormalizedUsage */
    public function normalize(array $input): array
    {
        $memoryUsed = self::measurement($input['memory_used_bytes'] ?? null);
        $memoryTotal = self::measurement($input['memory_total_bytes'] ?? null);
        $diskUsed = self::measurement($input['disk_used_bytes'] ?? null);
        $diskTotal = self::measurement($input['disk_total_bytes'] ?? null);
        $observed = $this->timestamp($input['observed_at'] ?? null);
        $ioObserved = $this->timestamp($input['io_observed_at'] ?? null);
        $ioStale = $this->stale($ioObserved);
        $ioSource = self::source($input['io_source'] ?? null, ['counter_delta', 'proxmox_rrd']);
        $interval = self::measurement($input['io_interval_seconds'] ?? null);
        $interval = $interval !== null && $interval > 0.0 && $interval <= 120.0 ? $interval : null;
        $allowRates = !$ioStale && $ioSource !== null && $interval !== null;
        $seriesKey = JsonMap::strings($input)['series_key'] ?? '';

        return [
            'cpu_percent' => self::measurement($input['cpu_percent'] ?? null),
            'memory_used_bytes' => $memoryUsed,
            'memory_total_bytes' => $memoryTotal,
            'disk_used_bytes' => $diskUsed,
            'disk_total_bytes' => $diskTotal,
            'disk_read_bytes' => self::measurement($input['disk_read_bytes'] ?? null),
            'disk_write_bytes' => self::measurement($input['disk_write_bytes'] ?? null),
            'net_rx_bytes' => self::measurement($input['net_rx_bytes'] ?? null),
            'net_tx_bytes' => self::measurement($input['net_tx_bytes'] ?? null),
            'memory_percent' => self::percentage($memoryUsed, $memoryTotal),
            'disk_percent' => self::percentage($diskUsed, $diskTotal),
            'io_interval_seconds' => $interval,
            'net_rx_bytes_per_second' => $allowRates ? self::measurement($input['net_rx_bytes_per_second'] ?? null) : null,
            'net_tx_bytes_per_second' => $allowRates ? self::measurement($input['net_tx_bytes_per_second'] ?? null) : null,
            'disk_read_bytes_per_second' => $allowRates ? self::measurement($input['disk_read_bytes_per_second'] ?? null) : null,
            'disk_write_bytes_per_second' => $allowRates ? self::measurement($input['disk_write_bytes_per_second'] ?? null) : null,
            'memory_source' => self::source($input['memory_source'] ?? null, ['guest_balloon', 'host_rss']),
            'load_source' => self::source($input['load_source'] ?? null, ['agent_heartbeat', 'proxmox_status', 'proxmox_cluster']),
            'io_source' => $ioSource,
            'observed_at' => $observed,
            'io_observed_at' => $ioObserved,
            'stale' => $this->stale($observed),
            'io_stale' => $ioStale,
            'series_key' => preg_match('/^[a-f0-9]{64}$/D', $seriesKey) === 1 ? $seriesKey : '',
        ];
    }

    private static function measurement(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) && $number >= 0.0 ? $number : null;
    }

    private static function percentage(?float $used, ?float $total): ?float
    {
        if ($used === null || $total === null || $total <= 0.0) {
            return null;
        }
        $percentage = $used / $total * 100.0;

        return is_finite($percentage) ? $percentage : null;
    }

    /** @param list<string> $allowed */
    private static function source(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private static function identifier(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }
        try {
            $time = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return null;
            }

            return $time->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    private function stale(?string $value): bool
    {
        if ($value === null) {
            return true;
        }
        $time = new \DateTimeImmutable($value);

        return $time < new \DateTimeImmutable('-2 minutes') || $time > new \DateTimeImmutable('+30 seconds');
    }
}
