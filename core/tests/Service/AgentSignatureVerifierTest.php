<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AgentSignatureVerifier;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class AgentSignatureVerifierTest extends TestCase
{
    public function testValidSignature(): void
    {
        $cache = new ArrayAdapter();
        $verifier = new AgentSignatureVerifier($cache, 300, 600);

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
        $verifier = new AgentSignatureVerifier($cache, 300, 600);

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
        $verifier = new AgentSignatureVerifier($cache, 10, 600);

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

    private function sign(string $agentId, string $secret, string $method, string $path, string $body, string $timestamp, string $nonce): string
    {
        $bodyHash = hash('sha256', $body);
        $payload = sprintf("%s\n%s\n%s\n%s\n%s", $agentId, strtoupper($method), $path, $bodyHash, $timestamp);
        if ($nonce !== '') {
            $payload .= "\n" . $nonce;
        }

        return hash_hmac('sha256', $payload, $secret);
    }
}
