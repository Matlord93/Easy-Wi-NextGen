<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\SinusbotNode;

class AgentEndpointResolver
{
    /** Loopback addresses that indicate a co-located (same-server) agent. */
    private const LOOPBACK = ['127.0.0.1', '::1', 'localhost'];

    public function __construct(
        private readonly int $defaultServicePort = 7456,
        private readonly string $defaultServiceScheme = 'http',
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default::AGENT_LOCAL_FALLBACK)%')]
        private readonly ?string $agentLocalFallback = null,
    ) {
    }

    public function resolveForNode(SinusbotNode $node): AgentEndpoint
    {
        return $this->resolveForAgent($node->getAgent());
    }

    public function resolveForAgent(Agent $agent): AgentEndpoint
    {
        $baseUrl = trim($agent->getServiceBaseUrl());
        if ($baseUrl !== '') {
            return new AgentEndpoint(rtrim($baseUrl, '/'));
        }

        $metadata = $agent->getMetadata();
        $metadata = is_array($metadata) ? $metadata : [];

        $heartbeatIp = trim((string) $agent->getLastHeartbeatIp());
        if ($heartbeatIp !== '') {
            $metadataPort = $metadata['agent_service_port'] ?? null;
            $port = is_numeric($metadataPort) ? (int) $metadataPort : $this->defaultPort();
            $metadataScheme = $metadata['agent_service_scheme'] ?? null;
            $scheme = is_string($metadataScheme) && trim($metadataScheme) !== ''
                ? trim($metadataScheme)
                : $this->defaultServiceScheme;

            // When the agent reported a loopback address we always use 127.0.0.1
            // so that both IPv4-only and IPv6-only kernels can connect.
            $resolvedIp = in_array($heartbeatIp, self::LOOPBACK, true) ? '127.0.0.1' : $heartbeatIp;

            return new AgentEndpoint(sprintf('%s://%s:%d', $scheme, $resolvedIp, $port));
        }

        // Same-server fallback: if AGENT_LOCAL_FALLBACK is set (or agent is
        // tagged as local in metadata), assume the agent runs on localhost.
        if ($this->agentLocalFallback !== null || ($metadata['local'] ?? false) === true) {
            return new AgentEndpoint(sprintf('%s://127.0.0.1:%d', $this->defaultServiceScheme, $this->defaultPort()));
        }

        throw new AgentConfigurationException('Agent-Konfiguration unvollständig: Service-URL fehlt.');
    }

    public function isLoopback(Agent $agent): bool
    {
        $url = trim($agent->getServiceBaseUrl());
        $host = $url !== '' ? (parse_url($url, PHP_URL_HOST) ?? '') : '';
        if (in_array($host, self::LOOPBACK, true)) {
            return true;
        }
        $ip = trim((string) $agent->getLastHeartbeatIp());
        return in_array($ip, self::LOOPBACK, true);
    }

    private function defaultPort(): int
    {
        return $this->defaultServicePort > 0 ? $this->defaultServicePort : 7456;
    }
}
