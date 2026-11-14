<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application;

use phpseclib3\Crypt\PublicKeyLoader;

final class VmProvisioningSettings
{
    public static function hostname(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,46}[a-z0-9])?$/D', $name)) {
            throw new \InvalidArgumentException('vm_config.invalid_hostname');
        }

        return $name;
    }

    /** @return list<string> */
    public static function publicKeys(mixed $value): array
    {
        if (!is_string($value) || strlen($value) > 32768) {
            throw new \InvalidArgumentException('vm_config.invalid_keys');
        }
        $keys = array_values(array_filter(array_map('trim', explode("\n", $value)), static fn (string $key): bool => '' !== $key));
        if (count($keys) < 1 || count($keys) > 8) {
            throw new \InvalidArgumentException('vm_config.invalid_keys');
        }
        foreach ($keys as $key) {
            if (preg_match('/[\x00-\x1f\x7f]/', $key) || !preg_match('/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+\/=]+(?: .*)?$/D', $key)) {
                throw new \InvalidArgumentException('vm_config.invalid_keys');
            }
            try {
                PublicKeyLoader::loadPublicKey($key);
            } catch (\Throwable) {
                throw new \InvalidArgumentException('vm_config.invalid_keys');
            }
        }

        return array_values(array_unique($keys));
    }

    public static function hasCloudInit(array $config): bool
    {
        foreach ($config as $device => $value) {
            if (preg_match('/^(ide|sata|scsi)\d+$/D', (string) $device) && is_string($value) && str_contains($value, 'media=cdrom') && preg_match('~(?:^|[:/])(?:vm-\d+-)?cloudinit(?:\.(?:raw|qcow2))?(?:,|$)~', $value)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array-key> */
    public static function bootDevices(array $config): array
    {
        $devices = [];
        foreach ($config as $key => $value) {
            if (is_string($value) && preg_match('/^(?:ide[0-3]|sata[0-5]|scsi(?:[0-9]|[12][0-9]|30)|virtio(?:[0-9]|1[0-5])|net(?:[0-9]|[12][0-9]|3[01]))$/D', (string) $key) && !str_contains(strtolower($value), 'cloudinit')) {
                $devices[] = $key;
            }
        }
        sort($devices);

        return $devices;
    }

    public static function proxmoxView(array $config, string $hostname): array
    {
        return ['hostname' => is_string($config['name'] ?? null) ? $config['name'] : $hostname, 'username' => is_string($config['ciuser'] ?? null) ? $config['ciuser'] : '', 'ssh_keys' => is_string($config['sshkeys'] ?? null) ? rawurldecode($config['sshkeys']) : '', 'supported' => self::hasCloudInit($config) && empty($config['cicustom'])];
    }
}
