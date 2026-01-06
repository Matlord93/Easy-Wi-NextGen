<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class AgentSignatureVerifier
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly int $maxSkewSeconds,
        private readonly int $nonceTtlSeconds,
    ) {
    }

    public function verify(Request $request, string $agentId, string $secret): void
    {
        $headerAgentId = (string) $request->headers->get('X-Agent-ID', '');
        if ($headerAgentId === '' || !hash_equals($agentId, $headerAgentId)) {
            throw new UnauthorizedHttpException('hmac', 'Missing or mismatched agent id.');
        }

        $timestamp = (string) $request->headers->get('X-Timestamp', '');
        $nonce = (string) $request->headers->get('X-Nonce', '');
        $signature = (string) $request->headers->get('X-Signature', '');

        if ($timestamp === '' || $signature === '') {
            throw new UnauthorizedHttpException('hmac', 'Missing signature headers.');
        }

        $parsedTimestamp = DateTimeImmutable::createFromFormat(DateTimeImmutable::RFC3339, $timestamp);
        if ($parsedTimestamp === false) {
            throw new UnauthorizedHttpException('hmac', 'Invalid timestamp.');
        }

        $now = new DateTimeImmutable();
        if (abs($now->getTimestamp() - $parsedTimestamp->getTimestamp()) > $this->maxSkewSeconds) {
            throw new UnauthorizedHttpException('hmac', 'Signature timestamp outside of allowed skew.');
        }

        if ($nonce === '') {
            throw new UnauthorizedHttpException('hmac', 'Missing nonce.');
        }

        $body = $request->getContent() ?? '';
        $bodyHash = hash('sha256', $body);
        $payload = sprintf(
            "%s\n%s\n%s\n%s\n%s",
            $agentId,
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            $bodyHash,
            $timestamp,
        );
        if ($nonce !== '') {
            $payload .= "\n" . $nonce;
        }

        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new UnauthorizedHttpException('hmac', 'Invalid signature.');
        }

        $nonceKey = sprintf('agent_nonce.%s.%s', $agentId, $nonce);
        $nonceItem = $this->cache->getItem($nonceKey);
        if ($nonceItem->isHit()) {
            throw new UnauthorizedHttpException('hmac', 'Nonce already used.');
        }
        $nonceItem->set(true);
        $nonceItem->expiresAfter($this->nonceTtlSeconds);
        $this->cache->save($nonceItem);
    }
}
