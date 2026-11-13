<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\AgentSignatureVerifier;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use App\Module\Core\Application\AgentNonceStore;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class AgentSignatureVerifierTest extends TestCase
{
    public function testValidSignature(): void
    {
        $cache = $this->nonceStore();
        $verifier = new AgentSignatureVerifier($cache, new NullLogger(), 300, 600);

        $agentId = 'agent-123';
        $secret = 'supersecret';
        $nonce = 'nonce-1';
        $timestamp = (new DateTimeImmutable())->format(DateTimeImmutable::RFC3339);
        $body = json_encode(['version' => '1.0.0', 'stats' => ['cpu' => '5%']], JSON_THROW_ON_ERROR);

        $request = Request::create('/agent/heartbeat', 'POST', [], [], [], [], $body);
        $signature = $this->sign($agentId, $secret, 'POST', '/agent/heartbeat', $body, $timestamp, $nonce);

        $request->headers->set('X-Agent-ID', $agentId);
        $request->headers->set('X-Timestamp', $timestamp);
        $request->headers->set('X-Nonce', $nonce);
        $request->headers->set('X-Signature', $signature);

        $verifier->verify($request, $agentId, $secret);

        $this->assertTrue(true);
    }

    public function testRejectsReusedNonce(): void
    {
        $cache = $this->nonceStore();
        $verifier = new AgentSignatureVerifier($cache, new NullLogger(), 300, 600);

        $agentId = 'agent-123';
        $secret = 'supersecret';
        $nonce = 'nonce-2';
        $timestamp = (new DateTimeImmutable())->format(DateTimeImmutable::RFC3339);
        $body = '';

        $request = Request::create('/agent/jobs', 'GET');
        $signature = $this->sign($agentId, $secret, 'GET', '/agent/jobs', $body, $timestamp, $nonce);

        $request->headers->set('X-Agent-ID', $agentId);
        $request->headers->set('X-Timestamp', $timestamp);
        $request->headers->set('X-Nonce', $nonce);
        $request->headers->set('X-Signature', $signature);

        $verifier->verify($request, $agentId, $secret);

        $this->expectException(UnauthorizedHttpException::class);
        $verifier->verify($request, $agentId, $secret);
    }

    public function testRejectsExpiredTimestamp(): void
    {
        $cache = $this->nonceStore();
        $verifier = new AgentSignatureVerifier($cache, new NullLogger(), 10, 600);

        $agentId = 'agent-123';
        $secret = 'supersecret';
        $nonce = 'nonce-3';
        $timestamp = (new DateTimeImmutable('-1 hour'))->format(DateTimeImmutable::RFC3339);
        $body = '';

        $request = Request::create('/agent/jobs', 'GET');
        $signature = $this->sign($agentId, $secret, 'GET', '/agent/jobs', $body, $timestamp, $nonce);

        $request->headers->set('X-Agent-ID', $agentId);
        $request->headers->set('X-Timestamp', $timestamp);
        $request->headers->set('X-Nonce', $nonce);
        $request->headers->set('X-Signature', $signature);

        $this->expectException(UnauthorizedHttpException::class);
        $verifier->verify($request, $agentId, $secret);
    }

    public function testAcceptsNormalizedMillisecondTimestamp(): void
    {
        $cache = $this->nonceStore();
        $verifier = new AgentSignatureVerifier($cache, new NullLogger(), 300, 600);

        $agentId = 'agent-123';
        $secret = 'supersecret';
        $nonce = 'nonce-4';
        $now = (new DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));
        $timestampWithMillis = $now->format('Y-m-d\\TH:i:s.v\\Z');
        $normalizedTimestamp = $now->format(DateTimeImmutable::RFC3339);
        $body = '{"ok":true}';

        $request = Request::create('/agent/heartbeat', 'POST', [], [], [], [], $body);
        $signature = $this->sign($agentId, $secret, 'POST', '/agent/heartbeat', $body, $normalizedTimestamp, $nonce);

        $request->headers->set('X-Agent-ID', $agentId);
        $request->headers->set('X-Timestamp', $timestampWithMillis);
        $request->headers->set('X-Nonce', $nonce);
        $request->headers->set('X-Signature', $signature);

        $verifier->verify($request, $agentId, $secret);

        $this->assertTrue(true);
    }

    public function testRejectsDifferentRawBodyEvenIfJsonEquivalent(): void
    {
        $cache = $this->nonceStore();
        $verifier = new AgentSignatureVerifier($cache, new NullLogger(), 300, 600);

        $agentId = 'agent-123';
        $secret = 'supersecret';
        $nonce = 'nonce-5';
        $timestamp = (new DateTimeImmutable())->format(DateTimeImmutable::RFC3339);
        $signedBody = '{"a":1,"b":2}';
        $requestBody = '{"b":2,"a":1}';

        $request = Request::create('/agent/heartbeat', 'POST', [], [], [], [], $requestBody);
        $signature = $this->sign($agentId, $secret, 'POST', '/agent/heartbeat', $signedBody, $timestamp, $nonce);

        $request->headers->set('X-Agent-ID', $agentId);
        $request->headers->set('X-Timestamp', $timestamp);
        $request->headers->set('X-Nonce', $nonce);
        $request->headers->set('X-Signature', $signature);

        $this->expectException(UnauthorizedHttpException::class);
        $verifier->verify($request, $agentId, $secret);
    }


    public function testAcceptsSignatureWhenProxyAddsTrailingSlashToPathInfo(): void
    {
        $cache = $this->nonceStore();
        $verifier = new AgentSignatureVerifier($cache, new NullLogger(), 300, 600);

        $agentId = 'agent-123';
        $secret = 'supersecret';
        $nonce = 'nonce-6';
        $timestamp = (new DateTimeImmutable())->format(DateTimeImmutable::RFC3339);
        $body = '{"name":"node"}';

        $request = Request::create('/api/v1/agent/register/', 'POST', [], [], [], [
            'REQUEST_URI' => '/api/v1/agent/register',
        ], $body);
        $signature = $this->sign($agentId, $secret, 'POST', '/api/v1/agent/register', $body, $timestamp, $nonce);

        $request->headers->set('X-Agent-ID', $agentId);
        $request->headers->set('X-Timestamp', $timestamp);
        $request->headers->set('X-Nonce', $nonce);
        $request->headers->set('X-Signature', $signature);

        $verifier->verify($request, $agentId, $secret);

        $this->assertTrue(true);
    }

    public function testTimestampVariantsCannotReplayTheSameSignedRequest(): void
    {
        $store = $this->nonceStore();
        $verifier = new AgentSignatureVerifier($store, new NullLogger(), 300, 1);
        $now = (new DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $canonical = $now->format(DateTimeImmutable::RFC3339);
        $signature = $this->sign('agent-123', 'secret', 'GET', '/agent/jobs', '', $canonical, 'shared-nonce');
        foreach ([$now->format('Y-m-d\\TH:i:s.v\\Z'), $canonical, $now->format('Y-m-d\\TH:i:s\\Z')] as $index => $timestamp) {
            $request = Request::create('/agent/jobs');
            $request->headers->add(['X-Agent-ID' => 'agent-123', 'X-Timestamp' => $timestamp, 'X-Nonce' => 'shared-nonce', 'X-Signature' => $signature]);
            try {
                $verifier->verify($request, 'agent-123', 'secret');
                self::assertSame(0, $index, 'A replay with an alternative timestamp was accepted.');
            } catch (UnauthorizedHttpException $error) {
                self::assertGreaterThan(0, $index);
                self::assertSame('nonce_reuse', $error->getMessage());
            }
        }
    }

    public function testFutureTimestampIsRememberedForTheEntireValidityWindow(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE agent_request_nonces (nonce_key VARCHAR(64) PRIMARY KEY, expires_at BIGINT NOT NULL)');
        $verifier = new AgentSignatureVerifier(new AgentNonceStore($connection), new NullLogger(), 300, 1);
        $future = new DateTimeImmutable('+290 seconds');
        $timestamp = $future->format(DateTimeImmutable::RFC3339);
        $request = Request::create('/agent/jobs');
        $request->headers->add(['X-Agent-ID' => 'agent-123', 'X-Timestamp' => $timestamp, 'X-Nonce' => 'future', 'X-Signature' => $this->sign('agent-123', 'secret', 'GET', '/agent/jobs', '', $timestamp, 'future')]);
        $verifier->verify($request, 'agent-123', 'secret');
        self::assertGreaterThanOrEqual($future->getTimestamp() + 301, (int) $connection->fetchOne('SELECT expires_at FROM agent_request_nonces'));
        // A separate verifier/process using the same database must still reject it.
        $this->expectException(UnauthorizedHttpException::class);
        (new AgentSignatureVerifier(new AgentNonceStore($connection), new NullLogger(), 300, 1))->verify($request, 'agent-123', 'secret');
    }

    public function testBadSignatureDoesNotReserveANonce(): void
    {
        $verifier = new AgentSignatureVerifier($this->nonceStore(), new NullLogger(), 300, 600);
        $timestamp = (new DateTimeImmutable())->format(DateTimeImmutable::RFC3339);
        $request = Request::create('/agent/jobs');
        $request->headers->add(['X-Agent-ID' => 'agent-123', 'X-Timestamp' => $timestamp, 'X-Nonce' => 'not-poisoned', 'X-Signature' => 'invalid']);
        try { $verifier->verify($request, 'agent-123', 'secret'); self::fail('Bad signature accepted.'); }
        catch (UnauthorizedHttpException $error) { self::assertSame('invalid_signature', $error->getMessage()); }
        $request->headers->set('X-Signature', $this->sign('agent-123', 'secret', 'GET', '/agent/jobs', '', $timestamp, 'not-poisoned'));
        $verifier->verify($request, 'agent-123', 'secret');
    }

    private function nonceStore(): AgentNonceStore
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE agent_request_nonces (nonce_key VARCHAR(64) PRIMARY KEY, expires_at BIGINT NOT NULL)');

        return new AgentNonceStore($connection);
    }

    private function sign(string $agentId, string $secret, string $method, string $path, string $body, string $timestamp, string $nonce): string
    {
        $payload = AgentSignatureVerifier::buildSignaturePayload($agentId, $method, $path, $timestamp, $nonce, $body);

        return hash_hmac('sha256', $payload, $secret);
    }
}
