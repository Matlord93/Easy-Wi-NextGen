<?php

declare(strict_types=1);

namespace App\Service;

final class DiskUsageFormatter
{
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

    public function formatPercent(float $value): string
    {
        return sprintf('%.1f%%', $value);
    }
}
