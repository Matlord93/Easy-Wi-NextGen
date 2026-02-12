<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\AgentConfigurationException;
use App\Module\Core\Application\AgentEndpointResolver;
use App\Module\Core\Domain\Entity\Agent;
use PHPUnit\Framework\TestCase;

final class AgentEndpointResolverTest extends TestCase
{
    public function testResolveUsesConfiguredServiceBaseUrl(): void
    {
        $agent = $this->newAgent();
        $agent->setServiceBaseUrl('https://agent.example.test/base/');

        $resolver = new AgentEndpointResolver();

        $endpoint = $resolver->resolveForAgent($agent);

        $this->assertSame('https://agent.example.test/base', $endpoint->getBaseUrl());
    }

    public function testResolveUsesHeartbeatMetadataWhenServiceUrlMissing(): void
    {
        $agent = $this->newAgent();
        $agent->recordHeartbeat([], 'v1', '10.0.0.5', [], [
            'agent_service_port' => 8087,
            'agent_service_scheme' => 'http',
        ]);

        $resolver = new AgentEndpointResolver();

        $endpoint = $resolver->resolveForAgent($agent);

        $this->assertSame('http://10.0.0.5:8087', $endpoint->getBaseUrl());
    }

    public function testResolveUsesDefaultPortWhenMetadataMissing(): void
    {
        $agent = $this->newAgent();
        $agent->recordHeartbeat([], 'v1', '10.0.0.9');

        $resolver = new AgentEndpointResolver(7456, 'http');

        $endpoint = $resolver->resolveForAgent($agent);

        $this->assertSame('http://10.0.0.9:7456', $endpoint->getBaseUrl());
    }

    public function testResolveThrowsWithoutServiceUrlAndHeartbeatIp(): void
    {
        $agent = $this->newAgent();
        $resolver = new AgentEndpointResolver();

        $this->expectException(AgentConfigurationException::class);
        $this->expectExceptionMessage('Agent-Konfiguration unvollständig: Service-URL fehlt.');

        $resolver->resolveForAgent($agent);
    }

    private function newAgent(): Agent
    {
        return new Agent('agent-1', [
            'key_id' => 'k',
            'nonce' => 'n',
            'ciphertext' => 'c',
        ], 'Agent 1');
    }
}
