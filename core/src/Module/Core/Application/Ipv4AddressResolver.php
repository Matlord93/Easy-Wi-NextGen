<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class Ipv4AddressResolver
{
    private const IGNORED_INTERFACE_PREFIXES = [
        'lo',
        'docker',
        'br-',
        'veth',
        'cni',
        'flannel',
        'weave',
        'virbr',
        'vmnet',
        'podman',
        'lxc',
        'zt',
        'tailscale',
        'wg',
        'tun',
        'tap',
        'qvb',
        'qvo',
    ];

    public function resolvePrimaryAddress(): string
    {
        $addresses = $this->resolveCandidateAddresses();

        foreach ($addresses as $address) {
            if ($this->isPublicIpv4($address)) {
                return $address;
            }
        }

        foreach ($addresses as $address) {
            if ($this->isPrivateIpv4($address)) {
                return $address;
            }
        }

        throw new AgentConfigurationException(
            'Keine gültige IPv4-Adresse zur automatischen Agent-Konfiguration gefunden.',
        );
    }

    /**
     * @return string[]
     */
    private function resolveCandidateAddresses(): array
    {
        $lines = [];
        $exitCode = 0;

        @exec('ip -4 -o addr show scope global', $lines, $exitCode);
        if ($exitCode !== 0 || $lines === []) {
            return [];
        }

        $candidates = [];
        foreach ($lines as $line) {
            if (!preg_match('/^\d+:\s+([^\s]+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)\//', $line, $matches)) {
                continue;
            }

            $interface = $this->normalizeInterfaceName($matches[1]);
            if ($this->isIgnoredInterface($interface)) {
                continue;
            }

            $address = $matches[2];
            if (!$this->isUsableIpv4($address)) {
                continue;
            }

            $candidates[$address] = true;
        }

        return array_keys($candidates);
    }

    private function normalizeInterfaceName(string $interface): string
    {
        $parts = explode('@', $interface, 2);
        return $parts[0];
    }

    private function isIgnoredInterface(string $interface): bool
    {
        foreach (self::IGNORED_INTERFACE_PREFIXES as $prefix) {
            if (str_starts_with($interface, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isUsableIpv4(string $address): bool
    {
        if (filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false) {
            return false;
        }

        if ($address === '0.0.0.0' || str_starts_with($address, '127.')) {
            return false;
        }

        if (str_starts_with($address, '169.254.')) {
            return false;
        }

        return true;
    }

    private function isPublicIpv4(string $address): bool
    {
        return filter_var(
            $address,
            \FILTER_VALIDATE_IP,
            \FILTER_FLAG_IPV4 | \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function isPrivateIpv4(string $address): bool
    {
        if (filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $ip = ip2long($address);
        if ($ip === false) {
            return false;
        }

        return $this->matchesCidr($ip, '10.0.0.0', 8)
            || $this->matchesCidr($ip, '172.16.0.0', 12)
            || $this->matchesCidr($ip, '192.168.0.0', 16);
    }

    private function matchesCidr(int $ip, string $network, int $prefix): bool
    {
        $networkLong = ip2long($network);
        if ($networkLong === false) {
            return false;
        }

        $mask = -1 << (32 - $prefix);

        return ($ip & $mask) === ($networkLong & $mask);
    }
}
