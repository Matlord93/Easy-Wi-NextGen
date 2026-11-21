<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

final class ProxmoxBackupConfiguration
{
    private const IGNORED_KEYS = ['digest', 'description', 'meta', 'vmgenid', 'parent', 'snaptime', 'lock'];

    /** @return array<string, string> */
    public static function archive(string $raw): array
    {
        $configuration = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, '[')) {
                // The root configuration ends where snapshot or pending sections begin.
                break;
            }
            if (1 !== preg_match('/^([a-zA-Z][a-zA-Z0-9_.-]*):\s*(.*)$/D', $line, $parts)
                || isset($configuration[$parts[1]])) {
                throw new \InvalidArgumentException('pve.error.restore_configuration');
            }
            $configuration[$parts[1]] = $parts[2];
        }

        return $configuration;
    }

    /**
     * Self-service restore must keep the VM identity, network, resources and device layout.
     * @param array<array-key, mixed> $configuration
     * @return array<string, string>
     */
    public static function comparable(array $configuration): array
    {
        $result = [];
        foreach ($configuration as $key => $value) {
            if (in_array($key, self::IGNORED_KEYS, true)) {
                continue;
            }
            if (!is_string($key) || (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value))) {
                throw new \InvalidArgumentException('pve.error.restore_configuration');
            }
            $result[$key] = is_bool($value) ? (string) (int) $value : (string) $value;
        }
        if (isset($result['tags'])) {
            $tags = explode(';', $result['tags']);
            sort($tags, SORT_STRING);
            $result['tags'] = implode(';', $tags);
        }
        ksort($result);

        return $result;
    }

    /** @param array<string, string> $configuration */
    public static function containerStorage(array $configuration): string
    {
        $root = $configuration['rootfs'] ?? '';
        if (1 !== preg_match('/^([a-zA-Z][a-zA-Z0-9_.-]*):/', $root, $parts)) {
            throw new \InvalidArgumentException('pve.error.restore_configuration');
        }
        $storage = $parts[1];
        foreach ($configuration as $key => $value) {
            if (1 === preg_match('/^mp\d+$/D', $key)
                && (!str_starts_with($value, $storage.':') || !in_array('backup=1', explode(',', $value), true))) {
                throw new \InvalidArgumentException('pve.error.restore_configuration');
            }
        }

        return $storage;
    }
}
