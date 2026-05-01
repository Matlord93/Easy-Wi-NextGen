<?php

declare(strict_types=1);

namespace App\Tests\Gameserver;

use App\Module\Core\Application\AgentEndpointResolver;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Gameserver\Application\Console\NodeEndpointMissingException;
use App\Module\Gameserver\Infrastructure\Grpc\GrpcConsoleAgentGrpcClient;
use App\Repository\InstanceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GrpcConsoleAgentGrpcClientTest extends TestCase
{
    public function testResolveEndpointUsesGameServiceMetadataUrl(): void
    {
        $client = new GrpcConsoleAgentGrpcClient(
            $this->createMock(InstanceRepository::class),
            $this->createMock(HttpClientInterface::class),
            $this->createMock(SecretsCrypto::class),
            new AgentEndpointResolver(),
        );

        $agent = new Agent('agent-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $agent->setMetadata(['grpc_endpoint' => 'https://node.example.test:9443']);

        self::assertSame('https://node.example.test:9443', $this->resolveEndpoint($client, $agent));
    }

    public function testResolveEndpointFallsBackToHeartbeatIpAndPort(): void
    {
        $client = new GrpcConsoleAgentGrpcClient(
            $this->createMock(InstanceRepository::class),
            $this->createMock(HttpClientInterface::class),
            $this->createMock(SecretsCrypto::class),
            new AgentEndpointResolver(),
        );

        $agent = new Agent('agent-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $agent->recordHeartbeat([], '1.0.0', '10.10.10.20', [], ['agent_service_port' => 7443, 'agent_service_scheme' => 'http']);

        self::assertSame('http://10.10.10.20:7443', $this->resolveEndpoint($client, $agent));
    }

    public function testResolveEndpointThrowsWhenNoAddressingDataIsAvailable(): void
    {
        $client = new GrpcConsoleAgentGrpcClient(
            $this->createMock(InstanceRepository::class),
            $this->createMock(HttpClientInterface::class),
            $this->createMock(SecretsCrypto::class),
            new AgentEndpointResolver(),
        );

        $agent = new Agent('agent-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);

        $this->expectException(NodeEndpointMissingException::class);
        $this->resolveEndpoint($client, $agent);
    }

    private function resolveEndpoint(GrpcConsoleAgentGrpcClient $client, Agent $agent): string
    {
        $reflection = new \ReflectionMethod($client, 'resolveEndpoint');
        $reflection->setAccessible(true);

        /** @var string $endpoint */
        $endpoint = $reflection->invoke($client, $agent);

        return $endpoint;
    }
}
