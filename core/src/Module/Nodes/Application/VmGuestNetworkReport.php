<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application;

/** Bounded observations from the guest; never an IP allocation or host inventory. */
final class VmGuestNetworkReport
{
    public static function empty(string $state): array
    {
        return ['state' => $state, 'interfaces' => [], 'limited' => false];
    }

    public static function fromQga(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input)) {
            return self::empty('invalid_response');
        }
        $rows = [];
        $limited = count($input) > 16;
        foreach (array_slice($input, 0, 16) as $row) {
            if (!is_array($row)) {
                $limited = true;
                continue;
            }
            $addresses = array_key_exists('ip-addresses', $row) ? $row['ip-addresses'] : [];
            if (!is_array($addresses) || !array_is_list($addresses)) {
                $limited = true;
                $addresses = [];
            }
            $limited = $limited || count($addresses) > 16;
            $ips = [];
            foreach (array_slice($addresses, 0, 16) as $ip) {
                if (!is_array($ip)) {
                    $limited = true;
                    continue;
                }
                $ips[] = ['family' => $ip['ip-address-type'] ?? null, 'address' => $ip['ip-address'] ?? null, 'prefix' => $ip['prefix'] ?? null];
            }
            $rows[] = ['name' => $row['name'] ?? null, 'mac' => $row['hardware-address'] ?? null, 'addresses' => $ips];
        }

        return self::sanitize(['state' => 'available', 'interfaces' => $rows, 'limited' => $limited]);
    }

    public static function sanitize(mixed $input): array
    {
        if (!is_array($input)) {
            return self::empty('not_reported');
        }
        $state = in_array($input['state'] ?? null, ['available', 'unsupported', 'unavailable', 'invalid_response', 'not_reported'], true) ? $input['state'] : 'invalid_response';
        if ('available' !== $state) {
            return self::empty($state);
        }
        $rows = $input['interfaces'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            return self::empty('invalid_response');
        }
        $out = [];
        $seenNames = [];
        $limited = ($input['limited'] ?? false) === true || count($rows) > 16;
        foreach (array_slice($rows, 0, 16) as $row) {
            $name = is_array($row) ? VmGuestAgentReport::text($row['name'] ?? null, 128) : null;
            if (null === $name || isset($seenNames[$name])) {
                $limited = true;
                continue;
            }
            $seenNames[$name] = true;
            $mac = $row['mac'] ?? null;
            if (null !== $mac && (!is_string($mac) || strlen($mac) > 59 || !in_array(count(explode(':', $mac)), [6, 8, 20], true) || !preg_match('/^[a-f0-9]{2}(?::[a-f0-9]{2})+$/iD', $mac))) {
                $mac = null;
                $limited = true;
            }
            $addresses = $row['addresses'] ?? [];
            if (!is_array($addresses) || !array_is_list($addresses)) {
                $limited = true;
                $addresses = [];
            }
            $limited = $limited || count($addresses) > 16;
            $valid = [];
            $seenIPs = [];
            foreach (array_slice($addresses, 0, 16) as $ip) {
                $family = is_array($ip) ? ($ip['family'] ?? null) : null;
                $flag = match ($family) {
                    'ipv4' => FILTER_FLAG_IPV4, 'ipv6' => FILTER_FLAG_IPV6, default => null,
                };
                $address = is_array($ip) ? ($ip['address'] ?? null) : null;
                $prefix = is_array($ip) ? ($ip['prefix'] ?? null) : null;
                if (null === $flag || !is_string($address) || strlen($address) > 45 || !filter_var($address, FILTER_VALIDATE_IP, $flag) || !is_int($prefix) || $prefix < 0 || $prefix > ('ipv4' === $family ? 32 : 128)) {
                    $limited = true;
                    continue;
                }
                $address = inet_ntop(inet_pton($address));
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
