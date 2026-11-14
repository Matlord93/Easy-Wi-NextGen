<?php

declare(strict_types=1);

namespace App\Infrastructure\Network;

use App\Module\Core\Domain\Entity\Agent;

final class ConfiguredAgentTarget
{
    private function __construct(private readonly string $address)
    {
    }

    public static function validate(string $url): void
    {
        if ($url !== '') {
            self::parse($url);
        }
    }

    public static function fromAgent(Agent $agent): ?self
    {
        $url = trim($agent->getAgentBaseUrl());

        return $url === '' ? null : self::parse($url);
    }

    private static function parse(string $url): self
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new \InvalidArgumentException('Agent URL contains invalid characters.');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['scheme'])
            || !in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Agent URL must be an HTTP(S) endpoint without credentials, query or fragment.');
        }
        $host = $parts['host'];
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false && preg_match('/\A[a-zA-Z0-9_](?:[a-zA-Z0-9_.-]*[a-zA-Z0-9_.])?\z/D', $host) !== 1) {
            throw new \InvalidArgumentException('Agent URL contains an invalid host.');
        }
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Agent port must be between 1 and 65535.');
        }

        return new self(sprintf('%s://%s:%d', $parts['scheme'] === 'https' ? 'ssl' : 'tcp', $host, $port));
    }

    /**
     * Only exposes the endpoint explicitly configured by an administrator for this node.
     * Private and public networks and custom ports are intentionally supported.
     * @psalm-taint-escape ssrf
     */
    public function socketAddress(): string
    {
        return $this->address;
    }
}
