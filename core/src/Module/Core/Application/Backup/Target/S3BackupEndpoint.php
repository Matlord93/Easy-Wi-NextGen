<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

/** Administrator-selected S3 endpoints can be private; their URL boundaries stay strict. */
final class S3BackupEndpoint
{
    /** @return array{origin:string,base_path:string,authority:string} */
    public static function parse(string $endpoint): array
    {
        if ('' === $endpoint || 1 === preg_match('/[\x00-\x20\x7f]/', $endpoint) || str_contains($endpoint, '\\')) {
            throw new \InvalidArgumentException('S3 endpoint must be an absolute HTTPS URL without whitespace or controls.');
        }
        $parts = parse_url($endpoint);
        if (!is_array($parts) || 'https' !== strtolower($parts['scheme'] ?? '') || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('S3 endpoint must use HTTPS without userinfo, query or fragment.');
        }
        $host = $parts['host'];
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            if (false === filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                throw new \InvalidArgumentException('Invalid S3 endpoint IPv6 host.');
            }
        } elseif (false === filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ('' === $host || strlen($host) > 253 || 1 === preg_match('/^[0-9.]+$/D', $host)) {
                throw new \InvalidArgumentException('Invalid S3 endpoint host.');
            }
            foreach (explode('.', rtrim($host, '.')) as $label) {
                if (1 !== preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/D', $label)) {
                    throw new \InvalidArgumentException('Invalid S3 endpoint hostname.');
                }
            }
        }
        $port = $parts['port'] ?? null;
        if (null !== $port && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException('Invalid S3 endpoint port.');
        }
        $path = $parts['path'] ?? '';
        if ('' !== $path && !str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Invalid S3 endpoint base path.');
        }
        $encoded = [];
        foreach (explode('/', rtrim($path, '/')) as $segment) {
            if (1 === preg_match('/%(?![0-9a-fA-F]{2})/', $segment)) {
                throw new \InvalidArgumentException('Invalid S3 endpoint path encoding.');
            }
            $decoded = rawurldecode($segment);
            if ('.' === $decoded || '..' === $decoded || 1 === preg_match('/[\x00-\x1f\x7f\/\\\\]/', $decoded)) {
                throw new \InvalidArgumentException('S3 endpoint base path must not contain dot segments, separators or controls.');
            }
            $encoded[] = rawurlencode($decoded);
        }
        $authority = $host.(null !== $port ? ':'.$port : '');

        return ['origin' => 'https://'.$authority, 'base_path' => implode('/', $encoded), 'authority' => $authority];
    }
}
