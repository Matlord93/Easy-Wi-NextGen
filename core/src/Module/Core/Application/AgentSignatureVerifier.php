<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class AgentSignatureVerifier
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly int $maxSkewSeconds,
        private readonly int $nonceTtlSeconds,
    ) {
    }

    public function verify(Request $request, string $agentId, string $secret): void
    {
        $headerAgentId = (string) $request->headers->get('X-Agent-ID', '');
        if ($headerAgentId === '' || !hash_equals($agentId, $headerAgentId)) {
            throw new UnauthorizedHttpException('hmac', 'unknown_agent');
        }

        $timestamp = trim((string) $request->headers->get('X-Timestamp', ''));
        $nonce = trim((string) $request->headers->get('X-Nonce', ''));
        $signature = strtolower(trim((string) $request->headers->get('X-Signature', '')));

        if ($timestamp === '' || $signature === '') {
            throw new UnauthorizedHttpException('hmac', 'missing_headers');
        }

        $parsedTimestamp = $this->parseTimestamp($timestamp);
        if ($parsedTimestamp === null) {
            throw new UnauthorizedHttpException('hmac', 'expired_timestamp');
        }

        $now = new DateTimeImmutable();
        if (abs($now->getTimestamp() - $parsedTimestamp->getTimestamp()) > $this->maxSkewSeconds) {
            throw new UnauthorizedHttpException('hmac', 'expired_timestamp');
        }

        if ($nonce === '') {
            throw new UnauthorizedHttpException('hmac', 'missing_headers');
        }

        $body = $request->getContent() ?? '';
        $method = strtoupper($request->getMethod());
        $path = $request->getPathInfo();
        $payload = self::buildSignaturePayload($agentId, $method, $path, $timestamp, $nonce, $body);
        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $signature)) {
            $normalizedTimestamp = $parsedTimestamp
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DateTimeInterface::RFC3339);
            if ($normalizedTimestamp !== $timestamp) {
                $normalizedPayload = self::buildSignaturePayload(
                    $agentId,
                    $method,
                    $path,
                    $normalizedTimestamp,
                    $nonce,
                    $body,
                );
                $normalizedExpected = hash_hmac('sha256', $normalizedPayload, $secret);
                if (hash_equals($normalizedExpected, $signature)) {
                    return;
                }
            } else {
                $normalizedExpected = null;
            }

            $bodyHash = hash('sha256', $body);
            $this->logger->warning('Agent signature mismatch.', [
                'reason' => 'invalid_signature',
                'agent_id' => $agentId,
                'method' => $method,
                'path' => $path,
                'body_hash_prefix' => substr($bodyHash, 0, 12),
                'timestamp' => $timestamp,
                'nonce' => $nonce,
                'signature_prefix' => substr($signature, 0, 12),
                'expected_signature_prefix' => substr($expected, 0, 12),
                'expected_signature_normalized_prefix' => $normalizedExpected === null ? null : substr($normalizedExpected, 0, 12),
            ]);
            throw new UnauthorizedHttpException('hmac', 'invalid_signature');
        }

        $nonceKey = sprintf('agent_nonce.%s.%s', $agentId, $nonce);
        $nonceItem = $this->cache->getItem($nonceKey);
        if ($nonceItem->isHit()) {
            throw new UnauthorizedHttpException('hmac', 'nonce_reuse');
        }
        $nonceItem->set(true);
        $nonceItem->expiresAfter($this->nonceTtlSeconds);
        $this->cache->save($nonceItem);
    }

    public static function buildSignaturePayload(
        string $agentId,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $rawBody,
    ): string {
        $bodyHash = hash('sha256', $rawBody);
        $payload = sprintf(
            "%s\n%s\n%s\n%s\n%s",
            $agentId,
            strtoupper($method),
            $path,
            $bodyHash,
            $timestamp,
        );
        if ($nonce !== '') {
            $payload .= "\n" . $nonce;
        }

        return $payload;
    }

    private function parseTimestamp(string $timestamp): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, $timestamp);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }

        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339, $timestamp);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }

        return null;
    }
}
