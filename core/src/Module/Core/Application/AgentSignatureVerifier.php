<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class AgentSignatureVerifier
{
    public function __construct(
        private readonly AgentNonceStore $nonces,
        private readonly LoggerInterface $logger,
        private readonly int $maxSkewSeconds,
        private readonly int $nonceTtlSeconds,
    ) {
    }

    public function verify(Request $request, string $agentId, string $secret): void
    {
        $headerAgentId = self::normalizeAgentIdHeaderValue($request->headers->get('X-Agent-ID'));
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
        $paths = $this->buildCandidatePaths($request);
        $path = $paths[0];

        $expected = null;
        $normalizedExpected = null;
        $matched = false;
        $normalizedTimestamp = $parsedTimestamp->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
        foreach ($paths as $candidatePath) {
            $payload = self::buildSignaturePayload($agentId, $method, $candidatePath, $timestamp, $nonce, $body);
            $candidateExpected = hash_hmac('sha256', $payload, $secret);
            $expected ??= $candidateExpected;
            if (hash_equals($candidateExpected, $signature)) {
                $matched = true;
                break;
            }
            if ($normalizedTimestamp !== $timestamp) {
                $normalizedPayload = self::buildSignaturePayload($agentId, $method, $candidatePath, $normalizedTimestamp, $nonce, $body);
                $candidateNormalizedExpected = hash_hmac('sha256', $normalizedPayload, $secret);
                $normalizedExpected ??= $candidateNormalizedExpected;
                if (hash_equals($candidateNormalizedExpected, $signature)) {
                    $matched = true;
                    break;
                }
            }
        }

        if (!$matched) {
            $bodyHash = hash('sha256', $body);
            $this->logger->warning('Agent signature mismatch.', [
                'reason' => 'invalid_signature',
                'agent_id' => $agentId,
                'method' => $method,
                'path' => $path,
                'path_candidates' => $paths,
                'body_hash_prefix' => substr($bodyHash, 0, 12),
                'timestamp' => $timestamp,
                'nonce' => $nonce,
                'signature_prefix' => substr($signature, 0, 12),
                'expected_signature_prefix' => substr($expected, 0, 12),
                'expected_signature_normalized_prefix' => $normalizedExpected === null ? null : substr($normalizedExpected, 0, 12),
            ]);
            throw new UnauthorizedHttpException('hmac', 'invalid_signature');
        }

        // A future-dated signature stays valid for almost twice the skew window.
        // Both signature paths must consume the same nonce until that window ends.
        $expiresAt = max($now->getTimestamp() + max(1, $this->nonceTtlSeconds), $parsedTimestamp->getTimestamp() + $this->maxSkewSeconds + 1);
        if (!$this->nonces->consume($agentId, $nonce, $expiresAt)) {
            throw new UnauthorizedHttpException('hmac', 'nonce_reuse');
        }

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

    public static function normalizeAgentIdHeaderValue(null|string|array $value): string
    {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        $segments = array_filter(array_map('trim', explode(',', $normalized)), static fn (string $segment): bool => $segment !== '');
        $normalized = $segments !== [] ? (string) reset($segments) : $normalized;

        return trim($normalized, "\"'");
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

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s.v\\Z', $timestamp, new DateTimeZone('UTC'));
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function buildCandidatePaths(Request $request): array
    {
        $candidates = [];

        $pathInfo = $request->getPathInfo();
        if ($pathInfo !== '') {
            $candidates[] = $pathInfo;
        }

        $requestUriPath = parse_url((string) $request->server->get('REQUEST_URI', ''), PHP_URL_PATH);
        if (is_string($requestUriPath) && $requestUriPath !== '') {
            $candidates[] = $requestUriPath;
        }

        $normalized = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            if ($candidate[0] !== '/') {
                $candidate = '/' . $candidate;
            }

            $normalized[$candidate] = true;
            if ($candidate !== '/') {
                $trimmed = rtrim($candidate, '/');
                $normalized[$trimmed === '' ? '/' : $trimmed] = true;
            }
        }

        if ($normalized === []) {
            return ['/'];
        }

        return array_keys($normalized);
    }
}
