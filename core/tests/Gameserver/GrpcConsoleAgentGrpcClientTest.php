<?php

declare(strict_types=1);

namespace App\Tests\Gameserver;

use App\Module\Core\Application\AgentEndpointResolver;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Infrastructure\Client\AgentHmacHeaderFactory;
use App\Module\Gameserver\Application\Console\NodeEndpointMissingException;
use App\Module\Gameserver\Infrastructure\Grpc\GrpcConsoleAgentGrpcClient;
use App\Repository\InstanceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GrpcConsoleAgentGrpcClientTest extends TestCase
{
    public function testResolveEndpointUsesGameServiceMetadataUrl(): void
    {
        $client = new GrpcConsoleAgentGrpcClient(
            $this->createMock(InstanceRepository::class),
            $this->createMock(HttpClientInterface::class),
            $this->createHmacHeaderFactory(),
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
            $this->createHmacHeaderFactory(),
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
            $this->createHmacHeaderFactory(),
            new AgentEndpointResolver(),
        );

        $agent = new Agent('agent-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);

        $this->expectException(NodeEndpointMissingException::class);
        $this->resolveEndpoint($client, $agent);
    }


    public function testConsoleLogsSendsHmacHeadersWithCursorRequestUri(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse('{"ok":true,"data":{"cursor":"","lines":[]}}', ['http_code' => 200]);
        });
        $instance = $this->createInstance(42, 7, 'agent-1');
        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(42)->willReturn($instance);
        $client = new GrpcConsoleAgentGrpcClient($repo, $http, $this->createHmacHeaderFactory('shared-secret'), new AgentEndpointResolver());

        $client->getConsoleLogs(42, 'abc');

        self::assertSame('GET', $captured['method']);
        self::assertSame('https://node.example.test:9443/v1/instances/42/console/logs?cursor=abc', $captured['url']);
        self::assertSame('agent-1', $captured['headers']['X-Agent-ID'] ?? null);
        self::assertSame('7', $captured['headers']['X-Customer-ID'] ?? null);
        self::assertArrayHasKey('X-Timestamp', $captured['headers']);
        self::assertArrayHasKey('X-Signature', $captured['headers']);
        self::assertArrayNotHasKey('Authorization', $captured['headers']);
        $expectedPayload = AgentHmacHeaderFactory::signaturePayload('agent-1', '7', 'GET', '/v1/instances/42/console/logs?cursor=abc', $captured['headers']['X-Timestamp']);
        self::assertSame(hash_hmac('sha256', $expectedPayload, 'shared-secret'), $captured['headers']['X-Signature']);
    }

    public function testConsoleCommandSendsHmacHeaders(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse('{"applied":true,"duplicate":false,"seq":12}', ['http_code' => 200]);
        });
        $instance = $this->createInstance(42, 7, 'agent-1');
        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('find')->with(42)->willReturn($instance);
        $client = new GrpcConsoleAgentGrpcClient($repo, $http, $this->createHmacHeaderFactory('shared-secret'), new AgentEndpointResolver());

        $client->sendCommand(new \App\Module\Gameserver\Application\Console\ConsoleCommandRequest(42, 'status', 'idem', 123, '7'));

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://node.example.test:9443/v1/instances/42/console/command', $captured['url']);
        $expectedPayload = AgentHmacHeaderFactory::signaturePayload('agent-1', '7', 'POST', '/v1/instances/42/console/command', $captured['headers']['X-Timestamp']);
        self::assertSame(hash_hmac('sha256', $expectedPayload, 'shared-secret'), $captured['headers']['X-Signature']);
    }

    private function resolveEndpoint(GrpcConsoleAgentGrpcClient $client, Agent $agent): string
    {
        $reflection = new \ReflectionMethod($client, 'resolveEndpoint');
        $reflection->setAccessible(true);

        /** @var string $endpoint */
        $endpoint = $reflection->invoke($client, $agent);

        return $endpoint;
    }
    private function createHmacHeaderFactory(string $secret = 'unused'): AgentHmacHeaderFactory
    {
        $encryption = $this->createMock(EncryptionService::class);
        $encryption->method('decrypt')->willReturn($secret);

        return new AgentHmacHeaderFactory($encryption);
    }

    private function createInstance(int $instanceId, int $customerId, string $agentId): Instance
    {
        $user = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($user, $customerId);
        $agent = new Agent($agentId, ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $agent->setMetadata(['grpc_endpoint' => 'https://node.example.test:9443']);
        $template = new Template('game', 'Game', null, null, null, [], './start.sh', [], [], [], [], '', '', [], []);
        $instance = new Instance($user, $template, $agent, 100, 1024, 10, null, InstanceStatus::Stopped);
        $this->setEntityId($instance, $instanceId);

        return $instance;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }

}
