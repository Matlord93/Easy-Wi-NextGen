<?php

declare(strict_types=1);

namespace App\Tests\Module\Gameserver\Infrastructure\Client;

use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class AgentGameServerClientTest extends TestCase
{
    public function testResolveBaseUrlUsesConfiguredAgentBaseUrl(): void
    {
        $agent = new Agent('agent-1', [
            'key_id' => 'unused',
            'nonce' => base64_encode('nonce'),
            'ciphertext' => base64_encode('ciphertext'),
        ]);
        $agent->setServiceBaseUrl('https://agent.example.test:9999/');

        $client = new AgentGameServerClient(
            new MockHttpClient(),
            new EncryptionService(null, null),
        );

        $resolvedUrl = $this->invokeResolveBaseUrl($client, $agent);

        self::assertSame('https://agent.example.test:9999', $resolvedUrl);
    }


    public function testResolveBaseUrlStripsFragmentsFromConfiguredBaseUrl(): void
    {
        $agent = new Agent('agent-1b', [
            'key_id' => 'unused',
            'nonce' => base64_encode('nonce'),
            'ciphertext' => base64_encode('ciphertext'),
        ]);
        $agent->setServiceBaseUrl('http://88.99.212.160:8087/#/instance/status');

        $client = new AgentGameServerClient(
            new MockHttpClient(),
            new EncryptionService(null, null),
        );

        $resolvedUrl = $this->invokeResolveBaseUrl($client, $agent);

        self::assertSame('http://88.99.212.160:8087', $resolvedUrl);
    }

    public function testResolveBaseUrlFallsBackToExplicitGamesvcUrlMetadata(): void
    {
        $agent = new Agent('agent-2', [
            'key_id' => 'unused',
            'nonce' => base64_encode('nonce'),
            'ciphertext' => base64_encode('ciphertext'),
        ]);
        $agent->setMetadata([
            'gamesvc_url' => 'http://gamesvc.internal:8088/',
        ]);

        $client = new AgentGameServerClient(
            new MockHttpClient(),
            new EncryptionService(null, null),
        );

        $resolvedUrl = $this->invokeResolveBaseUrl($client, $agent);

        self::assertSame('http://gamesvc.internal:8088', $resolvedUrl);
    }

    public function testResolveBaseUrlFallsBackToHeartbeatIpWithoutSystemPort(): void
    {
        $agent = new Agent('agent-3', [
            'key_id' => 'unused',
            'nonce' => base64_encode('nonce'),
            'ciphertext' => base64_encode('ciphertext'),
        ]);
        $agent->recordHeartbeat([], '1.0.0', '88.99.212.160');

        $client = new AgentGameServerClient(
            new MockHttpClient(),
            new EncryptionService(null, null),
        );

        $resolvedUrl = $this->invokeResolveBaseUrl($client, $agent);

        self::assertSame('https://88.99.212.160', $resolvedUrl);
    }


    public function testResolveBaseUrlUsesHeartbeatWithAgentProvidedServicePort(): void
    {
        $agent = new Agent('agent-3b', [
            'key_id' => 'unused',
            'nonce' => base64_encode('nonce'),
            'ciphertext' => base64_encode('ciphertext'),
        ]);
        $agent->recordHeartbeat([], '1.0.0', '88.99.212.160', metadata: [
            'agent_service_port' => 8087,
            'agent_service_scheme' => 'http',
        ]);

        $client = new AgentGameServerClient(
            new MockHttpClient(),
            new EncryptionService(null, null),
        );

        $resolvedUrl = $this->invokeResolveBaseUrl($client, $agent);

        self::assertSame('http://88.99.212.160:8087', $resolvedUrl);
    }

    public function testResolveBaseUrlThrowsWhenNoBaseUrlIsConfigured(): void
    {
        $agent = new Agent('agent-4', [
            'key_id' => 'unused',
            'nonce' => base64_encode('nonce'),
            'ciphertext' => base64_encode('ciphertext'),
        ]);

        $client = new AgentGameServerClient(
            new MockHttpClient(),
            new EncryptionService(null, null),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Game service base URL not configured.');

        $this->invokeResolveBaseUrl($client, $agent);
    }

    private function invokeResolveBaseUrl(AgentGameServerClient $client, Agent $agent): string
    {
        /** @var string $resolved */
        $resolved = (fn (Agent $target): string => $this->resolveBaseUrl($target))->call($client, $agent);

        return $resolved;
    }
}
