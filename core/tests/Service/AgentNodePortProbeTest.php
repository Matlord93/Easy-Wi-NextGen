<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AgentNodePortProbeTest extends TestCase
{
    public function testProbeAuthenticatesWithNodeSecretAndUnwrapsAgentResponse(): void
    {
        $encryption = $this->createStub(EncryptionService::class);
        $encryption->method('decrypt')->willReturn('test-node-secret');
        $node = new Agent('probe-node', []);
        $node->setServiceBaseUrl('https://node.example:8443');
        $checks = [['proto' => 'tcp', 'port' => 25565], ['proto' => 'udp', 'port' => 25565]];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($checks): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://node.example:8443/ports/check-free', $url);
            self::assertSame('Authorization: Bearer test-node-secret', $options['normalized_headers']['authorization'][0]);
            self::assertSame(['checks' => $checks], json_decode($options['body'], true));

            return new MockResponse(json_encode(['ok' => true, 'data' => ['results' => array_map(static fn ($c) => $c + ['free' => true], $checks)]]));
        });
        $results = (new AgentGameServerClient($http, $encryption))->checkFreePortsForNode($node, $checks);
        self::assertCount(2, $results);
        self::assertTrue($results[0]['free']);
    }

    public function testAuthenticationFailureDoesNotBecomeAnEmptyFreeList(): void
    {
        $encryption = $this->createStub(EncryptionService::class);
        $encryption->method('decrypt')->willReturn('test-node-secret');
        $node = new Agent('probe-node', []);
        $node->setServiceBaseUrl('https://node.example');
        $client = new AgentGameServerClient(new MockHttpClient(new MockResponse('{"results":[]}', ['http_code' => 401])), $encryption);
        $this->expectException(\RuntimeException::class);
        $client->checkFreePortsForNode($node, [['proto' => 'tcp', 'port' => 25565]]);
    }
}
