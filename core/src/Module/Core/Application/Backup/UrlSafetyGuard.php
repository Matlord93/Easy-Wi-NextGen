<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

final class UrlSafetyGuard
{
    /**
     * @return list<string>
     */
    public static function assertSafeHttpsEndpoint(string $url): array
    {
        $trimmed = trim($url);
        if ($trimmed === '' || str_starts_with($trimmed, '//')) {
            throw new \InvalidArgumentException('Target URL must be an absolute https URL and cannot be scheme-relative.');
        }

        $parts = parse_url($trimmed);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new \InvalidArgumentException('Backup target URL must use https.');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost') {
            throw new \InvalidArgumentException('Backup target host is invalid.');
        }

        $ips = self::resolveHostIps($host);
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new \InvalidArgumentException(sprintf('Backup target resolves to blocked IP range (%s).', $ip));
            }
        }

        return $ips;
    }

    /** @return list<string> */
    private static function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = dns_get_record($host, DNS_A + DNS_AAAA);
        if (!is_array($records) || $records === []) {
            throw new \InvalidArgumentException('Backup target host could not be resolved.');
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        if ($ips === []) {
            throw new \InvalidArgumentException('Backup target host did not resolve to usable addresses.');
        }

        return array_values(array_unique($ips));
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
