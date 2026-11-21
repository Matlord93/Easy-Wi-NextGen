<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Proxmox;

final readonly class ProxmoxIpRange
{
    public string $first;
    public string $last;
    public string $gateway;

    public function __construct(public int $family, string $first, string $last, public int $prefix, string $gateway)
    {
        if (!in_array($family, [4, 6], true) || $prefix < 1 || $prefix > (4 === $family ? 30 : 127)) {
            throw self::invalid();
        }
        $flag = 4 === $family ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
        $this->first = self::canonical($first, $flag, false);
        $this->last = self::canonical($last, $flag, false);
        $this->gateway = self::canonical($gateway, $flag, 6 === $family);
        $firstKey = self::key($this->first);
        $lastKey = self::key($this->last);
        $gatewayKey = self::key($this->gateway);
        $network = self::network($firstKey, $prefix);
        $linkLocalGateway = 6 === $family && self::network($gatewayKey, 10) === 'fe800000000000000000000000000000';
        if (strcmp($firstKey, $lastKey) > 0 || self::network($lastKey, $prefix) !== $network || $firstKey === $network
            || (4 === $family && $lastKey === self::network($lastKey, $prefix, true))
            || (!$linkLocalGateway && (self::network($gatewayKey, $prefix) !== $network || $gatewayKey === $network || (4 === $family && $gatewayKey === self::network($gatewayKey, $prefix, true))))
            || (strcmp($gatewayKey, $firstKey) >= 0 && strcmp($gatewayKey, $lastKey) <= 0)) {
            throw self::invalid();
        }
    }

    private static function canonical(string $address, int $flag, bool $allowLinkLocal): string
    {
        $address = trim($address);
        $flags = $flag | ($allowLinkLocal ? 0 : FILTER_FLAG_NO_RES_RANGE);
        if (!filter_var($address, FILTER_VALIDATE_IP, $flags)) {
            throw self::invalid();
        }
        $key = self::key($address);
        $firstByte = hexdec(substr($key, 0, 2));
        if ((FILTER_FLAG_IPV4 === $flag && ($firstByte === 0 || $firstByte >= 224))
            || (FILTER_FLAG_IPV6 === $flag && (str_starts_with($key, 'ff') || str_starts_with($key, '00000000000000000000ffff') || in_array($key, ['00000000000000000000000000000000', '00000000000000000000000000000001'], true)))) {
            throw self::invalid();
        }

        return self::address($key);
    }

    public static function key(string $address): string
    {
        $packed = inet_pton($address);
        if (false === $packed) {
            throw self::invalid();
        }

        return bin2hex($packed);
    }

    public static function address(string $key): string
    {
        $packed = hex2bin($key);
        $address = false === $packed ? false : inet_ntop($packed);
        if (false === $address) {
            throw self::invalid();
        }

        return $address;
    }

    private static function network(string $key, int $prefix, bool $last = false): string
    {
        $result = '';
        for ($offset = 0; $offset < strlen($key); ++$offset) {
            $bits = min(4, max(0, $prefix - 4 * $offset));
            $mask = 15 ^ ((1 << (4 - $bits)) - 1);
            $part = (int) hexdec($key[$offset]) & $mask;
            $result .= dechex($last ? $part | ($mask ^ 15) : $part);
        }

        return $result;
    }

    /** Increment a fixed-width, lexicographically ordered address key. */
    public static function successor(string $key): ?string
    {
        for ($offset = strlen($key) - 1; $offset >= 0; --$offset) {
            if ('f' !== $key[$offset]) {
                $key[$offset] = dechex((int) hexdec($key[$offset]) + 1);

                return $key;
            }
            $key[$offset] = '0';
        }

        return null;
    }

    private static function invalid(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('pve.error.ip_pool_range');
    }
}
