<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\SinusbotNode;

final class AgentEndpointResolver
{
    public function __construct(
        private readonly int $defaultServicePort = 7456,
        private readonly string $defaultServiceScheme = 'http',
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
            if (is_numeric($metadataPort)) {
                $metadataScheme = $metadata['agent_service_scheme'] ?? null;
                $scheme = is_string($metadataScheme) && trim($metadataScheme) !== ''
                    ? trim($metadataScheme)
                    : $this->defaultServiceScheme;

                return new AgentEndpoint(sprintf('%s://%s:%d', $scheme, $heartbeatIp, (int) $metadataPort));
            }

            return new AgentEndpoint(sprintf('%s://%s:%d', $this->defaultServiceScheme, $heartbeatIp, $this->defaultPort()));
        }

        throw new AgentConfigurationException('Agent-Konfiguration unvollständig: Service-URL fehlt.');
    }

    private function defaultPort(): int
    {
        return $this->defaultServicePort > 0 ? $this->defaultServicePort : 7456;
    }
}
