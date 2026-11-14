<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

use App\Module\Nodes\Domain\Proxmox\ProxmoxApiException;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Reuses provider-computed rates; never differentiates RRD values a second time. */
final readonly class ProxmoxRrdUsage
{
    public function __construct(private ProxmoxApiClient $api, private CacheInterface $cache)
    {
    }

    public function forGuest(ProxmoxConnection $connection, string $node, string $kind, int $vmId, array $status): array
    {
        $uptime = $status['uptime'] ?? null;
        if (($status['status'] ?? '') !== 'running' || !is_numeric($uptime) || !is_finite((float) $uptime) || $uptime <= 0) {
            return [];
        }
        $key = 'vm_io_v1.'.hash('sha256', json_encode([$connection->baseUrl, $connection->tokenId, hash('sha256', $connection->tokenSecret), $connection->verifyTls, $node, $kind, $vmId]));
        $sample = $this->cache->get($key, function (ItemInterface $item) use ($connection, $node, $kind, $vmId): array {
            $item->expiresAfter(15);
            try {
                return self::latest($this->api->guestRrd($connection, $node, $vmId, $kind), time());
            } catch (ProxmoxApiException) {
                return [];
            }
        });
        if (!$sample) {
            return [];
        }
        $end = strtotime($sample['io_observed_at']);
        // Do not show an interval overlapping an earlier VM run/VM-ID reuse.
        if ($end < time() - 120 || $end - $sample['io_interval_seconds'] < time() - (float) $uptime) {
            return [];
        }

        return $sample;
    }

    /**
     * @return array{disk_write_bytes_per_second?: float, disk_read_bytes_per_second?: float,
     * net_tx_bytes_per_second?: float, net_rx_bytes_per_second?: float, io_observed_at?: string,
     * io_interval_seconds?: int, io_source?: string}
     */
    public static function latest(array $rows, int $now): array
    {
        $byTime = [];
        foreach (array_slice($rows, 0, 256) as $row) {
            $time = is_array($row) ? ($row['time'] ?? null) : null;
            if (is_int($time) && $time >= $now - 300 && $time <= $now + 5) {
                $byTime[$time] = $row;
            }
        }
        krsort($byTime, SORT_NUMERIC);
        $times = array_keys($byTime);
        foreach ($times as $index => $time) {
            if ($time < $now - 120 || !isset($times[$index + 1])) {
                continue;
            }
            $interval = $time - $times[$index + 1];
            if ($interval <= 0 || $interval > 120) {
                continue;
            }
            $result = [];
            foreach (['netin' => 'net_rx', 'netout' => 'net_tx', 'diskread' => 'disk_read', 'diskwrite' => 'disk_write'] as $source => $key) {
                $value = $byTime[$time][$source] ?? null;
                if (is_numeric($value) && is_finite((float) $value) && $value >= 0) {
                    $result[$key.'_bytes_per_second'] = (float) $value;
                }
            }
            if ($result) {
                return $result + ['io_observed_at' => gmdate(DATE_ATOM, $time), 'io_interval_seconds' => $interval, 'io_source' => 'proxmox_rrd'];
            }
        }

        return [];
    }
}
