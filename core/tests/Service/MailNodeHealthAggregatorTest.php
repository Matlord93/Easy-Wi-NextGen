<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\AgentEndpoint;
use App\Module\Core\Application\AgentEndpointResolver;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\MailNode;
use App\Module\PanelAdmin\Application\MailNodeHealthAggregator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class MailNodeHealthAggregatorTest extends TestCase
{
    public function testAddsMissingChecksAndMarksOfflineAsFailed(): void
    {
        $mailNode = new MailNode('mail-a', 'imap.example.com', 993, 'smtp.example.com', 587, 'https://webmail.example.com');

        $http = $this->createMock(HttpClientInterface::class);
        $resolver = $this->createMock(AgentEndpointResolver::class);
        $crypto = $this->createMock(SecretsCrypto::class);

        $aggregator = new MailNodeHealthAggregator($http, $resolver, $crypto, new NullLogger());
        $result = $aggregator->aggregate([$mailNode], []);

        self::assertFalse($result['nodes'][0]['overall_ok']);
        self::assertArrayHasKey('postfix_installed', $result['nodes'][0]['checks']);
        self::assertSame('no agent mapped to this mail node', $result['nodes'][0]['checks']['postfix_installed']['message']);
    }

    public function testNormalizesAgentChecksWithoutMailContentExposure(): void
    {
        $mailNode = new MailNode('mail-a', 'imap.example.com', 993, 'smtp.example.com', 587, 'https://webmail.example.com');
        $agent = new Agent('agent-1', ['key_id' => 'a', 'nonce' => 'b', 'ciphertext' => 'c']);
        $agent->setStatus(Agent::STATUS_ACTIVE);
        $agent->setServiceBaseUrl('http://127.0.0.1:7456');

        $domain = $this->createMock(\App\Module\Core\Domain\Entity\Domain::class);
        $webspace = $this->createMock(\App\Module\Core\Domain\Entity\Webspace::class);
        $mailDomain = $this->createMock(MailDomain::class);
        $mailDomain->method('getNode')->willReturn($mailNode);
        $mailDomain->method('getDomain')->willReturn($domain);
        $domain->method('getWebspace')->willReturn($webspace);
        $webspace->method('getNode')->willReturn($agent);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->with(false)->willReturn(json_encode([
            'checks' => ['postfix_installed' => ['ok' => true, 'message' => 'postfix found']],
            'sender' => 'hidden@example.com',
            'subject' => 'secret',
        ], JSON_THROW_ON_ERROR));

        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willReturn($response);
        $resolver = $this->createMock(AgentEndpointResolver::class);
        $resolver->method('resolveForAgent')->willReturn(new AgentEndpoint('http://127.0.0.1:7456'));
        $crypto = $this->createMock(SecretsCrypto::class);
        $crypto->method('decrypt')->willReturn('');

        $aggregator = new MailNodeHealthAggregator($http, $resolver, $crypto, new NullLogger());
        $result = $aggregator->aggregate([$mailNode], [$mailDomain]);

        self::assertTrue($result['nodes'][0]['checks']['postfix_installed']['ok']);
        self::assertArrayNotHasKey('sender', $result['nodes'][0]);
        self::assertArrayNotHasKey('subject', $result['nodes'][0]);
    }
}
