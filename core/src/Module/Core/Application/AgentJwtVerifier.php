<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class AgentJwtVerifier
{
    public function __construct(
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $maxTtlSeconds,
        private readonly int $clockSkewSeconds,
    ) {
    }

    public function verify(Request $request, string $agentId, string $secret): void
    {
        $authorization = trim((string) $request->headers->get('Authorization', ''));
        if (!str_starts_with($authorization, 'Bearer ')) {
            throw new UnauthorizedHttpException('bearer', 'missing_bearer_token');
        }

        $token = trim(substr($authorization, 7));
        if ($token === '') {
            throw new UnauthorizedHttpException('bearer', 'missing_bearer_token');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new UnauthorizedHttpException('bearer', 'invalid_token');
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
        $headerRaw = $this->base64UrlDecode($headerEncoded);
        $payloadRaw = $this->base64UrlDecode($payloadEncoded);
        if ($headerRaw === null || $payloadRaw === null) {
            throw new UnauthorizedHttpException('bearer', 'invalid_token');
        }

        $header = json_decode($headerRaw, true);
        $claims = json_decode($payloadRaw, true);
        if (!is_array($header) || !is_array($claims)) {
            throw new UnauthorizedHttpException('bearer', 'invalid_token');
        }

        if (($header['alg'] ?? null) !== 'HS256') {
            throw new UnauthorizedHttpException('bearer', 'invalid_token_alg');
        }

        $expected = $this->base64UrlEncode(hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $secret, true));
        if (!hash_equals($expected, $signatureEncoded)) {
            throw new UnauthorizedHttpException('bearer', 'invalid_token_signature');
        }

        $now = time();
        $exp = (int) ($claims['exp'] ?? 0);
        $iat = (int) ($claims['iat'] ?? 0);
        $sub = trim((string) ($claims['sub'] ?? ''));
        $iss = trim((string) ($claims['iss'] ?? ''));
        $aud = trim((string) ($claims['aud'] ?? ''));
        $jti = trim((string) ($claims['jti'] ?? ''));

        if ($sub === '' || !hash_equals($agentId, $sub)) {
            throw new UnauthorizedHttpException('bearer', 'invalid_token_subject');
        }

        if ($iss === '' || !hash_equals($this->issuer, $iss)) {
            throw new UnauthorizedHttpException('bearer', 'invalid_token_issuer');
        }

        if ($aud === '' || !hash_equals($this->audience, $aud)) {
            throw new UnauthorizedHttpException('bearer', 'invalid_token_audience');
        }

        if ($exp <= 0 || $iat <= 0) {
            throw new UnauthorizedHttpException('bearer', 'invalid_token_time_claims');
        }

        if (($exp - $iat) > $this->maxTtlSeconds) {
            throw new UnauthorizedHttpException('bearer', 'token_ttl_exceeded');
        }

        if ($now > ($exp + $this->clockSkewSeconds)) {
            throw new UnauthorizedHttpException('bearer', 'token_expired');
        }

        if (($iat - $this->clockSkewSeconds) > $now) {
            throw new UnauthorizedHttpException('bearer', 'token_issued_in_future');
        }

        $nonce = trim((string) $request->headers->get('X-Nonce', ''));
        if ($nonce !== '' && $jti !== '' && !hash_equals($nonce, $jti)) {
            throw new UnauthorizedHttpException('bearer', 'token_nonce_mismatch');
        }
    }

    private function base64UrlDecode(string $value): ?string
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        return is_string($decoded) ? $decoded : null;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
