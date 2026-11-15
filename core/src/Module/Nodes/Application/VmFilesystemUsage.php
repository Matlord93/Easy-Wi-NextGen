<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application;

/**
 * Only mounted, local guest filesystems; never provisioned/image allocation.
 * @psalm-type FilesystemUsage = array{state: string, used_bytes: float|null, total_bytes: float|null, filesystems: int, partial: bool}
 */
final class VmFilesystemUsage
{
    /** @return FilesystemUsage */
    public static function unavailable(): array
    {
        return ['state' => 'unavailable', 'used_bytes' => null, 'total_bytes' => null, 'filesystems' => 0, 'partial' => false];
    }

    /** @return FilesystemUsage */
    public static function fromQga(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > 128) {
            return self::unavailable();
        }
        $seen = [];
        $used = 0.0;
        $total = 0.0;
        $partial = false;
        foreach ($input as $row) {
            if (!is_array($row)) {
                $partial = true;
                continue;
            }
            $type = is_string($row['type'] ?? null) ? strtolower($row['type']) : '';
            // A zram device can contain ext4; its filesystem type alone does not
            // identify it as volatile memory. Do not filter by mountpoint.
            if (self::memoryDevice($row['name'] ?? null) || self::memoryBackedDisks($row['disk'] ?? null)) {
                continue;
            }
            if (in_array($type, ['tmpfs', 'devtmpfs', 'proc', 'sysfs', 'overlay', 'squashfs', 'iso9660', 'udf', 'cdfs', 'nfs', 'nfs4', 'cifs', 'smbfs', '9p', 'virtiofs'], true)) {
                continue;
            }
            // Ignore RAM, network, optical and overlay mounts, including Windows CD-ROMs.
            if (!in_array($type, ['ext2', 'ext3', 'ext4', 'erofs', 'xfs', 'btrfs', 'f2fs', 'jfs', 'reiserfs', 'ufs', 'ntfs', 'refs', 'fat', 'fat16', 'fat32', 'vfat', 'exfat'], true)) {
                $partial = $partial || !empty($row['disk']);
                continue;
            }
            $name = VmGuestAgentReport::text($row['name'] ?? null, 512);
            $mount = VmGuestAgentReport::text($row['mountpoint'] ?? null, 512);
            $size = self::bytes($row['total-bytes'] ?? null);
            $taken = self::bytes($row['used-bytes'] ?? null);
            if ($name === null || $mount === null || $size === null || $size <= 0 || $taken === null || $taken > $size) {
                $partial = true;
                continue;
            }
            // A bind mount or btrfs subvolume must not multiply the same filesystem.
            $key = $type.':'.$name;
            if (isset($seen[$key])) {
                if ($seen[$key] !== [$taken, $size]) {
                    // Inconsistent duplicates cannot produce a reliable aggregate.
                    return self::unavailable();
                }
                continue;
            }
            $seen[$key] = [$taken, $size];
            $used += $taken;
            $total += $size;
        }
        if (!$seen || $total > 9007199254740991) {
            return self::unavailable();
        }

        return ['state' => 'available', 'used_bytes' => $used, 'total_bytes' => $total, 'filesystems' => count($seen), 'partial' => $partial];
    }

    /** @return FilesystemUsage */
    public static function sanitize(mixed $input): array
    {
        if (!is_array($input) || ($input['state'] ?? null) !== 'available') {
            return self::unavailable();
        }
        $used = self::bytes($input['used_bytes'] ?? null);
        $total = self::bytes($input['total_bytes'] ?? null);
        $count = $input['filesystems'] ?? null;
        if ($used === null || $total === null || $total <= 0 || $used > $total || !is_int($count) || $count < 1 || $count > 128 || !is_bool($input['partial'] ?? null)) {
            return self::unavailable();
        }

        return ['state' => 'available', 'used_bytes' => $used, 'total_bytes' => $total, 'filesystems' => $count, 'partial' => $input['partial']];
    }

    private static function bytes(mixed $value): ?float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 9007199254740991 || floor((float) $value) !== (float) $value) {
            return null;
        }

        return (float) $value;
    }

    private static function memoryDevice(mixed $value): bool
    {
        return is_string($value) && preg_match('~^(?:/dev/)?(?:zram|ram)[0-9]+$~D', $value) === 1;
    }

    private static function memoryBackedDisks(mixed $disks): bool
    {
        if (!is_array($disks) || count($disks) > 128) {
            return false;
        }
        foreach ($disks as $disk) {
            if (is_array($disk) && self::memoryDevice($disk['dev'] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
