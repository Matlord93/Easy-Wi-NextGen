<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

/** New managed volumes only: existing slots and host paths cannot be overwritten. */
final class ProxmoxExtraDisk
{
    public static function slots(array $config, string $kind): array
    {
        $slots = [];
        $buses = 'qemu' === $kind ? ['scsi' => 30, 'virtio' => 15] : ('lxc' === $kind ? ['mp' => 255] : []);
        foreach ($buses as $bus => $maximum) {
            for ($i = 0; $i <= $maximum; ++$i) {
                if (!array_key_exists($bus.$i, $config)) {
                    $slots[] = $bus.$i;
                }
            }
        }

        return $slots;
    }

    public static function specification(array $config, string $kind, array $input): array
    {
        if ('1' !== ($input['confirm'] ?? null)) {
            throw new \InvalidArgumentException('pve.error.confirm');
        }
        $digest = $config['digest'] ?? null;
        $device = $input['device'] ?? null;
        $storage = $input['storage'] ?? null;
        $size = filter_var($input['size_gib'] ?? null, FILTER_VALIDATE_INT);
        if (!is_string($digest) || '' === $digest || ($input['expected_digest'] ?? null) !== $digest) {
            throw new \InvalidArgumentException('pve.error.disk_changed');
        }
        if (!is_string($device) || !in_array($device, self::slots($config, $kind), true) || !is_string($storage) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $storage) || false === $size || $size < 1 || $size > 65536 || !empty($config['lock'])) {
            throw new \InvalidArgumentException('pve.error.extra_disk');
        }
        $mountpoint = '';
        if ('lxc' === $kind) {
            $mountpoint = $input['mountpoint'] ?? '';
            if (!is_string($mountpoint) || !preg_match('~^/(?:mnt|srv)/[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}$~D', $mountpoint)) {
                throw new \InvalidArgumentException('pve.error.mountpoint');
            }
            foreach ($config as $key => $value) {
                if (is_string($value) && preg_match('/^mp\d+$/D', (string) $key) && in_array('mp='.$mountpoint, explode(',', $value), true)) {
                    throw new \InvalidArgumentException('pve.error.mountpoint');
                }
            }
        }

        return ['device' => $device, 'storage' => $storage, 'size_gib' => $size, 'mountpoint' => $mountpoint, 'digest' => $digest];
    }
}
