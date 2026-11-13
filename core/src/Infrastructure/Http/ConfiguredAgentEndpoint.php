<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

/** Administrator-selected agent endpoints may intentionally use private IPs. */
final class ConfiguredAgentEndpoint
{
    /**
     * This is a privileged connectivity probe, not a public URL fetcher. Callers
     * must authenticate an administrator and verify CSRF before accepting a URL.
     * The result has a fixed transport and one validated host/port, no wrappers,
     * userinfo, URL credentials, query strings or redirect handling.
     *
     * @psalm-taint-escape ssrf
     */
    public static function socketAddress(string $url): string
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('Invalid agent URL.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Agent URL must use http or https without credentials, query or fragment.');
        }
        $host = trim($parts['host'] ?? '', '[]');
        if ('' === $host || (false === filter_var($host, FILTER_VALIDATE_IP) && false === filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            throw new \InvalidArgumentException('Agent URL has an invalid host.');
        }
        $tls = 'https' === strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($tls ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Agent URL has an invalid port.');
        }

        return ($tls ? 'ssl' : 'tcp').'://'.(str_contains($host, ':') ? '['.$host.']' : $host).':'.$port;
    }
}
