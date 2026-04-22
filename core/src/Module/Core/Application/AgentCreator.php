<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;

class AgentCreator
{
    public function __construct(
        private readonly Ipv4AddressResolver $ipv4AddressResolver,
        private readonly int $defaultServicePort,
    ) {
    }

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $secretPayload
     */
    public function create(string $agentId, array $secretPayload, ?string $name = null): Agent
    {
        $agent = new Agent($agentId, $secretPayload, $name);
        $serviceUrl = $this->buildServiceUrl($this->ipv4AddressResolver->resolvePrimaryAddress());
        $agent->setServiceBaseUrl($serviceUrl);

        return $agent;
    }

    private function buildServiceUrl(string $ipv4Address): string
    {
        $port = $this->defaultServicePort > 0 ? $this->defaultServicePort : 7456;

        return sprintf('http://%s:%d', $ipv4Address, $port);
    }
}
