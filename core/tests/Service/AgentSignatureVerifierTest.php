<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\AgentSignatureVerifier;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Psr\Log\NullLogger;

final class AgentSignatureVerifierTest extends TestCase
{
    public function testValidSignature(): void
    {
        $cache = new ArrayAdapter();
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
        $cache = new ArrayAdapter();
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
        $cache = new ArrayAdapter();
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
        $cache = new ArrayAdapter();
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
        $cache = new ArrayAdapter();
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

    private function sign(string $agentId, string $secret, string $method, string $path, string $body, string $timestamp, string $nonce): string
    {
        $payload = AgentSignatureVerifier::buildSignaturePayload($agentId, $method, $path, $timestamp, $nonce, $body);

        return hash_hmac('sha256', $payload, $secret);
    }
}
