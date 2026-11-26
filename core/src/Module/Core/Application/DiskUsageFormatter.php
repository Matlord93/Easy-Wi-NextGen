<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Instance;

final class DiskUsageFormatter
{
    /** @return array{disk_used_bytes:int,disk_limit_bytes:int,disk_used_human:string,disk_limit_human:string,disk_percent:float,disk_measurement_state:'unknown'|'stale'|'current',disk_last_scanned_at:?\DateTimeImmutable,disk_scan_error:?string} */
    public function describeInstance(Instance $instance, ?\DateTimeImmutable $now = null): array
    {
        $used = $instance->getDiskUsedBytes();
        $limit = $instance->getDiskLimitBytes();
        $scanned = $instance->getDiskLastScannedAt();
        $error = $instance->getDiskScanError();
        $staleAfter = $instance->getNode()->getDiskScanIntervalSeconds() + 120;
        $state = match (true) {
            $scanned === null, $used === 0 && $error !== null => 'unknown',
            $error !== null, $scanned->getTimestamp() + $staleAfter < ($now ?? new \DateTimeImmutable())->getTimestamp() => 'stale',
            default => 'current',
        };

        return [
            'disk_used_bytes' => $used,
            'disk_limit_bytes' => $limit,
            'disk_used_human' => $state === 'unknown' ? '—' : $this->formatBytes($used),
            'disk_limit_human' => $this->formatBytes($limit),
            'disk_percent' => $limit > 0 ? (float) ($used / $limit) * 100.0 : 0.0,
            'disk_measurement_state' => $state,
            'disk_last_scanned_at' => $scanned,
            'disk_scan_error' => $error,
        ];
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = (int) floor(log($bytes, 1024));
        $value = $bytes / (1024 ** $index);

        return sprintf('%.1f %s', $value, $units[$index] ?? 'B');
    }

    public function formatPercent(float $value, bool $trimZeros = false): string
    {
        $number = sprintf('%.1f', $value);
        return ($trimZeros ? rtrim(rtrim($number, '0'), '.') : $number) . '%';
    }
}
