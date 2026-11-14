<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

/** Only attached, writable storage volumes with a known configured size. */
final class ProxmoxDisks
{
    public static function validDevice(string $kind, string $device): bool
    {
        if ($kind === 'lxc') {
            if ($device === 'rootfs') {
                return true;
            }
            if (preg_match('/^mp([0-9]{1,3})$/D', $device, $match) !== 1) {
                return false;
            }

            return (int) $match[1] <= 255 && (string) (int) $match[1] === $match[1];
        }

        return $kind === 'qemu' && preg_match('/^(?:ide[0-3]|sata[0-5]|scsi(?:[0-9]|[12][0-9]|30)|virtio(?:[0-9]|1[0-5]))$/D', $device) === 1;
    }

    /** @return list<array{device: array-key, size_bytes: int, minimum_gib: int}> */
    public static function attached(array $config, string $kind): array
    {
        $disks = [];
        foreach ($config as $device => $raw) {
            if (!self::validDevice($kind, (string) $device) || !is_string($raw)) {
                continue;
            }
            $parts = explode(',', $raw);
            $volume = array_shift($parts);
            if (!preg_match('~^[A-Za-z][A-Za-z0-9_.-]*:[A-Za-z0-9_./-]+$~D', $volume) || str_contains(strtolower($volume), 'cloudinit')) {
                continue;
            }
            $options = [];
            $invalid = false;
            foreach ($parts as $part) {
                [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
                if (isset($options[$key])) {
                    $invalid = true;
                }
                $options[$key] = $value;
            }
            if ($invalid || isset($options['import-from']) || ('disk' !== ($options['media'] ?? 'disk')) || in_array($options['ro'] ?? '0', ['1', 'on', 'yes', 'true'], true) || in_array($options['shared'] ?? '0', ['1', 'on', 'yes', 'true'], true)) {
                continue;
            }
            $size = self::bytes($options['size'] ?? '');
            if (null !== $size) {
                $disks[] = ['device' => $device, 'size_bytes' => $size, 'minimum_gib' => intdiv($size, 1073741824) + 1];
            }
        }
        usort($disks, static fn (array $a, array $b): int => strnatcmp($a['device'], $b['device']));

        return $disks;
    }

    private static function bytes(string $raw): ?int
    {
        if (!preg_match('/^([0-9]{1,15}(?:\.[0-9]{1,6})?)([KMGT]?)$/D', $raw, $match)) {
            return null;
        }
        $factor = match ($match[2]) {
            'K' => 1024, 'M' => 1048576, 'G' => 1073741824, 'T' => 1099511627776, default => 1,
        };
        [$whole, $fraction] = array_pad(explode('.', $match[1], 2), 2, '');
        if ((int) $whole > intdiv(70368744177664, $factor)) {
            return null;
        }
        $fractionalBytes = (int) str_pad($fraction, 6, '0') * $factor;
        $bytes = (int) $whole * $factor + intdiv($fractionalBytes, 1000000);

        return 0 === $fractionalBytes % 1000000 && $bytes >= 1 && $bytes <= 70368744177664 ? $bytes : null;
    }
}
