<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application;

use App\Module\Core\Domain\Entity\Agent;

/** A numeric, credential-free view of provider metrics. Missing values stay unknown. */
final class InfrastructureUsage
{
    public function node(Agent $node): array
    {
        $m = $node->getLastHeartbeatStats()['metrics'] ?? [];

        return $this->normalize([
            'series_key' => hash('sha256', 'node:'.$node->getId()),
            'load_source' => 'agent_heartbeat',
            'cpu_percent' => $m['cpu']['percent'] ?? null,
            'memory_used_bytes' => $m['memory']['used'] ?? null,
            'memory_total_bytes' => $m['memory']['total'] ?? null,
            'disk_used_bytes' => $m['disk']['used'] ?? null,
            'disk_total_bytes' => $m['disk']['total'] ?? null,
            'observed_at' => $m['collected_at'] ?? $node->getLastHeartbeatAt()?->format(DATE_ATOM),
        ]);
    }

    public function kvm(Agent $node, string $uuid): array
    {
        $snapshot = $node->getLastHeartbeatStats()['kvm_usage'] ?? [];
        $m = $snapshot['guests'][$uuid] ?? [];
        $m['observed_at'] = $snapshot['observed_at'] ?? null;
        $m['series_key'] = hash('sha256', 'kvm:'.$node->getId().':'.$uuid);
        $m['load_source'] = 'agent_heartbeat';

        return $this->normalize($m);
    }

    public function proxmox(array $m): array
    {
        return $this->normalize([
            'series_key' => hash('sha256', 'pve:'.($m['node'] ?? '').':'.($m['vmid'] ?? 'node')),
            'load_source' => isset($m['vmid']) ? 'proxmox_status' : 'proxmox_cluster',
            'cpu_percent' => isset($m['cpu']) && is_numeric($m['cpu']) ? (float) $m['cpu'] * 100 : null,
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

    public function normalize(array $input): array
    {
        $out = [];
        foreach (['cpu_percent', 'memory_used_bytes', 'memory_total_bytes', 'disk_used_bytes', 'disk_total_bytes', 'disk_read_bytes', 'disk_write_bytes', 'net_rx_bytes', 'net_tx_bytes'] as $key) {
            $v = $input[$key] ?? null;
            $out[$key] = is_numeric($v) && is_finite((float) $v) && (float) $v >= 0 ? (float) $v : null;
        }
        $out['memory_percent'] = null !== $out['memory_used_bytes'] && $out['memory_total_bytes'] > 0 ? $out['memory_used_bytes'] / $out['memory_total_bytes'] * 100 : null;
        $out['disk_percent'] = null !== $out['disk_used_bytes'] && $out['disk_total_bytes'] > 0 ? $out['disk_used_bytes'] / $out['disk_total_bytes'] * 100 : null;
        foreach (['memory_percent', 'disk_percent'] as $key) {
            if (null !== $out[$key] && !is_finite($out[$key])) {
                $out[$key] = null;
            }
        }
        $out['memory_source'] = in_array($input['memory_source'] ?? null, ['guest_balloon', 'host_rss'], true) ? $input['memory_source'] : null;
        $out['observed_at'] = $this->timestamp($input['observed_at'] ?? null);
        $out['stale'] = $this->stale($out['observed_at']);
        $out['series_key'] = is_string($input['series_key'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $input['series_key']) ? $input['series_key'] : '';
        $out['load_source'] = in_array($input['load_source'] ?? null, ['agent_heartbeat', 'proxmox_status', 'proxmox_cluster'], true) ? $input['load_source'] : null;
        $out['io_observed_at'] = $this->timestamp($input['io_observed_at'] ?? null);
        $out['io_stale'] = $this->stale($out['io_observed_at']);
        $out['io_source'] = in_array($input['io_source'] ?? null, ['counter_delta', 'proxmox_rrd'], true) ? $input['io_source'] : null;
        $interval = $input['io_interval_seconds'] ?? null;
        $out['io_interval_seconds'] = is_numeric($interval) && is_finite((float) $interval) && $interval > 0 && $interval <= 120 ? (float) $interval : null;
        foreach (['net_rx', 'net_tx', 'disk_read', 'disk_write'] as $key) {
            $value = $input[$key.'_bytes_per_second'] ?? null;
            $out[$key.'_bytes_per_second'] = !$out['io_stale'] && $out['io_source'] && $out['io_interval_seconds'] && is_numeric($value) && is_finite((float) $value) && $value >= 0 ? (float) $value : null;
        }

        return $out;
    }

    private function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }
        try {
            $time = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
                return null;
            }

            return $time->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    private function stale(?string $value): bool
    {
        if (!$value) {
            return true;
        }
        $time = new \DateTimeImmutable($value);

        return $time < new \DateTimeImmutable('-2 minutes') || $time > new \DateTimeImmutable('+30 seconds');
    }
}
