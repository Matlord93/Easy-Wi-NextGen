<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\Application;

final class BookedResourceUsageAggregator
{
    /**
     * @param array<int, array{booked_cpu_cores:?float, booked_ram_bytes:?int, used_cpu_percent:?float, used_ram_bytes:?int}> $rows
     * @return array{
     *   total_booked_cpu_cores:?float,
     *   total_used_cpu_cores:?float,
     *   total_cpu_percent:?float,
     *   total_booked_ram_bytes:?int,
     *   total_used_ram_bytes:?int,
     *   total_ram_percent:?float
     * }
     */
    public function aggregate(array $rows): array
    {
        $bookedCpuTotal = 0.0;
        $usedCpuCoresTotal = 0.0;
        $hasBookedCpu = false;
        $hasUsedCpu = false;

        $bookedRamTotal = 0;
        $usedRamTotal = 0;
        $hasBookedRam = false;
        $hasUsedRam = false;

        foreach ($rows as $row) {
            $bookedCpu = is_numeric($row['booked_cpu_cores'] ?? null) ? (float) $row['booked_cpu_cores'] : null;
            $usedCpuPercent = is_numeric($row['used_cpu_percent'] ?? null) ? (float) $row['used_cpu_percent'] : null;
            if ($bookedCpu !== null && $bookedCpu > 0) {
                $hasBookedCpu = true;
                $bookedCpuTotal += $bookedCpu;
                if ($usedCpuPercent !== null && $usedCpuPercent >= 0) {
                    $hasUsedCpu = true;
                    $usedCpuCoresTotal += ($usedCpuPercent / 100) * $bookedCpu;
                }
            }

            $bookedRam = is_numeric($row['booked_ram_bytes'] ?? null) ? (int) $row['booked_ram_bytes'] : null;
            $usedRam = is_numeric($row['used_ram_bytes'] ?? null) ? (int) $row['used_ram_bytes'] : null;
            if ($bookedRam !== null && $bookedRam > 0) {
                $hasBookedRam = true;
                $bookedRamTotal += $bookedRam;
                if ($usedRam !== null && $usedRam >= 0) {
                    $hasUsedRam = true;
                    $usedRamTotal += $usedRam;
                }
            }
        }

        $totalCpuPercent = null;
        if ($hasBookedCpu && $hasUsedCpu && $bookedCpuTotal > 0) {
            $totalCpuPercent = ($usedCpuCoresTotal / $bookedCpuTotal) * 100;
        }

        $totalRamPercent = null;
        if ($hasBookedRam && $hasUsedRam && $bookedRamTotal > 0) {
            $totalRamPercent = ($usedRamTotal / $bookedRamTotal) * 100;
        }

        return [
            'total_booked_cpu_cores' => $hasBookedCpu ? $bookedCpuTotal : null,
            'total_used_cpu_cores' => $hasUsedCpu ? $usedCpuCoresTotal : null,
            'total_cpu_percent' => $totalCpuPercent,
            'total_booked_ram_bytes' => $hasBookedRam ? $bookedRamTotal : null,
            'total_used_ram_bytes' => $hasUsedRam ? $usedRamTotal : null,
            'total_ram_percent' => $totalRamPercent,
        ];
    }
}
