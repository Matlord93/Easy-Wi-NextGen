<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

final class MusicbotWebradioUrlValidator
{
    private const BLOCKED_SCHEMES = ['file', 'ftp', 'ftps', 'data', 'javascript'];
    private const ALLOWED_SCHEMES = ['http', 'https'];

    // Private / reserved IPv4 ranges (CIDR notation as prefix strings for simplicity)
    private const BLOCKED_IPV4_PREFIXES = [
        '10.',
        '172.16.', '172.17.', '172.18.', '172.19.',
        '172.20.', '172.21.', '172.22.', '172.23.',
        '172.24.', '172.25.', '172.26.', '172.27.',
        '172.28.', '172.29.', '172.30.', '172.31.',
        '192.168.',
        '127.',
        '169.254.',
        '0.',
    ];

    private const BLOCKED_HOSTNAMES = ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback'];

    public function validate(string $url): void
    {
        $url = trim($url);
        if ($url === '') {
            throw new \InvalidArgumentException('Stream URL must not be empty.');
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'])) {
            throw new \InvalidArgumentException('Stream URL is not a valid URL.');
        }

        $scheme = strtolower($parsed['scheme']);
        if (in_array($scheme, self::BLOCKED_SCHEMES, true)) {
            throw new \InvalidArgumentException(sprintf('URL scheme "%s" is not allowed. Only http and https stream URLs are permitted.', $scheme));
        }
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new \InvalidArgumentException(sprintf('Only http and https stream URLs are allowed (got "%s").', $scheme));
        }

        if (!isset($parsed['host']) || $parsed['host'] === '') {
            throw new \InvalidArgumentException('Stream URL is not a valid URL.');
        }

        $host = strtolower(trim($parsed['host'], '[]'));

        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            throw new \InvalidArgumentException('Stream URLs pointing to localhost are not allowed.');
        }

        // Block IPv6 loopback (::1) and link-local (fe80::)
        if ($host === '::1' || str_starts_with($host, 'fe80:')) {
            throw new \InvalidArgumentException('Stream URLs pointing to loopback or link-local IPv6 addresses are not allowed.');
        }

        // Block private/reserved IPv4 ranges
        foreach (self::BLOCKED_IPV4_PREFIXES as $prefix) {
            if (str_starts_with($host, $prefix)) {
                throw new \InvalidArgumentException('Stream URLs pointing to private or reserved IP addresses are not allowed.');
            }
        }

        // Reject suspiciously long URLs (> 2 048 chars)
        if (strlen($url) > 2048) {
            throw new \InvalidArgumentException('Stream URL is too long (max 2 048 characters).');
        }
    }
}
