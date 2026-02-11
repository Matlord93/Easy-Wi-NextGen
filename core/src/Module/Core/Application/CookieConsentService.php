<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Symfony\Component\HttpFoundation\Request;

final class CookieConsentService
{
    public const COOKIE_NAME = 'cookie_consent';
    public const VERSION = 1;

    /**
     * @return array{version:int, necessary:bool, statistics:bool, marketing:bool}
     */
    public function defaultConsent(): array
    {
        return [
            'version' => self::VERSION,
            'necessary' => true,
            'statistics' => false,
            'marketing' => false,
        ];
    }

    /**
     * @return array{version:int, necessary:bool, statistics:bool, marketing:bool}|null
     */
    public function readFromRequest(Request $request): ?array
    {
        $raw = $request->cookies->get(self::COOKIE_NAME);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        return $this->normalize($raw);
    }

    /**
     * @return array{version:int, necessary:bool, statistics:bool, marketing:bool}
     */
    public function createFromFlags(bool $statistics, bool $marketing): array
    {
        return [
            'version' => self::VERSION,
            'necessary' => true,
            'statistics' => $statistics,
            'marketing' => $marketing,
        ];
    }

    public function encode(array $consent): string
    {
        $normalized = $this->normalize($consent);

        return json_encode($normalized, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string|array<string, mixed> $raw
     * @return array{version:int, necessary:bool, statistics:bool, marketing:bool}
     */
    private function normalize(string|array $raw): array
    {
        $payload = $raw;
        if (is_string($raw)) {
            $decoded = json_decode(rawurldecode($raw), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $defaults = $this->defaultConsent();

        return [
            'version' => isset($payload['version']) ? (int) $payload['version'] : $defaults['version'],
            'necessary' => true,
            'statistics' => (bool) ($payload['statistics'] ?? false),
            'marketing' => (bool) ($payload['marketing'] ?? false),
        ];
    }
}
