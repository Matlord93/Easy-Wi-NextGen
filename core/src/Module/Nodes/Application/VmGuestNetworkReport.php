<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;

/**
 * Bounded observations from the guest.
 * @psalm-type GuestNetwork = array{state: string, interfaces: list<array{name:string, mac:?string, addresses:list<array{family:'ipv4'|'ipv6', address:string, prefix:int}>}>, limited: bool}
 */
final class VmGuestNetworkReport
{
    /** @return GuestNetwork */
    public static function empty(string $state): array
    {
        return ['state' => $state, 'interfaces' => [], 'limited' => false];
    }

    /** @return GuestNetwork */
    public static function fromQga(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input)) {
            return self::empty('invalid_response');
        }
        $rows = [];
        $limited = count($input) > 16;
        foreach (array_keys(array_slice($input, 0, 16)) as $index) {
            $row = JsonArray::fromValue($input[$index]);
            if (null === $row) {
                $limited = true;
                continue;
            }
            $addresses = JsonArray::fromValue(array_key_exists('ip-addresses', $row) ? $row['ip-addresses'] : []);
            if (null === $addresses || !array_is_list($addresses)) {
                $limited = true;
                $addresses = [];
            }
            $limited = $limited || count($addresses) > 16;
            $ips = [];
            foreach (array_keys(array_slice($addresses, 0, 16)) as $index) {
                $ip = JsonArray::fromValue($addresses[$index]);
                if (null === $ip) {
                    $limited = true;
                    continue;
                }
                $ips[] = ['family' => $ip['ip-address-type'] ?? null, 'address' => $ip['ip-address'] ?? null, 'prefix' => $ip['prefix'] ?? null];
            }
            $rows[] = ['name' => $row['name'] ?? null, 'mac' => $row['hardware-address'] ?? null, 'addresses' => $ips];
        }

        return self::sanitize(['state' => 'available', 'interfaces' => $rows, 'limited' => $limited]);
    }

    private static function prefix(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /** @return GuestNetwork */
    public static function sanitize(mixed $input): array
    {
        if (!is_array($input)) {
            return self::empty('not_reported');
        }
        $strings = JsonMap::strings($input);
        $state = in_array($strings['state'] ?? null, ['available', 'unsupported', 'unavailable', 'invalid_response', 'not_reported'], true) ? $strings['state'] : 'invalid_response';
        if ('available' !== $state) {
            return self::empty($state);
        }
        $rows = JsonArray::fromValue($input['interfaces'] ?? null);
        if (null === $rows || !array_is_list($rows)) {
            return self::empty('invalid_response');
        }
        $out = [];
        $seenNames = [];
        $limited = ($input['limited'] ?? false) === true || count($rows) > 16;
        foreach (array_keys(array_slice($rows, 0, 16)) as $index) {
            $row = JsonArray::orEmpty($rows[$index]);
            $name = VmGuestAgentReport::text($row['name'] ?? null, 128);
            if (null === $name || isset($seenNames[$name])) {
                $limited = true;
                continue;
            }
            $seenNames[$name] = true;
            $mac = JsonMap::strings($row)['mac'] ?? null;
            if (isset($row['mac']) && (null === $mac || strlen($mac) > 59 || !in_array(count(explode(':', $mac)), [6, 8, 20], true) || !preg_match('/^[a-f0-9]{2}(?::[a-f0-9]{2})+$/iD', $mac))) {
                $mac = null;
                $limited = true;
            }
            $addresses = JsonArray::fromValue($row['addresses'] ?? []);
            if (null === $addresses || !array_is_list($addresses)) {
                $limited = true;
                $addresses = [];
            }
            $limited = $limited || count($addresses) > 16;
            $valid = [];
            $seenIPs = [];
            foreach (array_keys(array_slice($addresses, 0, 16)) as $index) {
                $ip = JsonArray::orEmpty($addresses[$index]);
                $strings = JsonMap::strings($ip);
                $family = $strings['family'] ?? null;
                if ('ipv4' !== $family && 'ipv6' !== $family) {
                    $limited = true;
                    continue;
                }
                $flag = 'ipv4' === $family ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
                $address = $strings['address'] ?? null;
                $prefix = self::prefix($ip['prefix'] ?? null);
                if (!is_string($address) || strlen($address) > 45 || !filter_var($address, FILTER_VALIDATE_IP, $flag) || !is_int($prefix) || $prefix < 0 || $prefix > ('ipv4' === $family ? 32 : 128)) {
                    $limited = true;
                    continue;
                }
                $packed = inet_pton($address);
                $address = false === $packed ? false : inet_ntop($packed);
                if (false === $address) {
                    $limited = true;
                    continue;
                }
                $key = $address.'/'.$prefix;
                if (isset($seenIPs[$key])) {
                    $limited = true;
                    continue;
                }
                $seenIPs[$key] = true;
                $valid[] = ['family' => $family, 'address' => $address, 'prefix' => $prefix];
            }
            $out[] = ['name' => $name, 'mac' => is_string($mac) ? strtolower($mac) : null, 'addresses' => $valid];
        }

        return ['state' => 'available', 'interfaces' => $out, 'limited' => $limited];
    }
}
