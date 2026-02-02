<?php

declare(strict_types=1);

namespace App\Module\Unifi\Application;

use App\Module\Unifi\Domain\Entity\UnifiSettings;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class UnifiApiClient
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPortForwardRules(UnifiSettings $settings, string $password): array
    {
        $response = $this->request($settings, $password, 'GET', sprintf('/proxy/network/api/s/%s/rest/portforward', $settings->getSite()));
        $payload = $this->decodeResponse($response);

        $records = $payload['data'] ?? $payload['results'] ?? $payload['rules'] ?? [];
        if (!is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map([$this, 'normalizeRule'], $records)));
    }

    /**
     * @return array<string, mixed>
     */
    public function createRule(UnifiSettings $settings, string $password, array $payload): array
    {
        $response = $this->request($settings, $password, 'POST', sprintf('/proxy/network/api/s/%s/rest/portforward', $settings->getSite()), [
            'json' => $payload,
        ]);

        return $this->decodeResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateRule(UnifiSettings $settings, string $password, string $ruleId, array $payload): array
    {
        $response = $this->request($settings, $password, 'PUT', sprintf('/proxy/network/api/s/%s/rest/portforward/%s', $settings->getSite(), $ruleId), [
            'json' => $payload,
        ]);

        return $this->decodeResponse($response);
    }

    public function deleteRule(UnifiSettings $settings, string $password, string $ruleId): void
    {
        $this->request($settings, $password, 'DELETE', sprintf('/proxy/network/api/s/%s/rest/portforward/%s', $settings->getSite(), $ruleId));
    }

    /**
     * @return array{headers: array<string, list<string>>, status: int, content: string}
     */
    private function request(UnifiSettings $settings, string $password, string $method, string $path, array $options = []): array
    {
        $baseUrl = $settings->getBaseUrl();
        if ($baseUrl === '') {
            throw new UnifiApiException('UniFi base URL not configured.', 'unifi_offline');
        }

        $cookie = $this->login($settings, $password);
        $options['headers']['Cookie'] = $cookie;
        $options['timeout'] = 10;
        $options['verify_peer'] = $settings->isVerifyTls();
        $options['verify_host'] = $settings->isVerifyTls();

        try {
            $response = $this->httpClient->request($method, $baseUrl . $path, $options);
        } catch (TransportExceptionInterface $exception) {
            $code = str_contains($exception->getMessage(), 'SSL') ? 'tls_failed' : 'unifi_offline';
            throw new UnifiApiException('Failed to reach UniFi API.', $code);
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new UnifiApiException(sprintf('UniFi API error: HTTP %d', $statusCode), 'unifi_offline');
        }

        return [
            'headers' => $response->getHeaders(false),
            'status' => $statusCode,
            'content' => $response->getContent(false),
        ];
    }

    private function login(UnifiSettings $settings, string $password): string
    {
        $baseUrl = $settings->getBaseUrl();
        $username = $settings->getUsername();
        if ($username === '' || $password === '') {
            throw new UnifiApiException('UniFi credentials missing.', 'auth_failed');
        }

        $payload = [
            'username' => $username,
            'password' => $password,
            'remember' => true,
        ];

        $paths = ['/api/auth/login', '/api/login'];
        $lastError = null;
        foreach ($paths as $path) {
            try {
                $response = $this->httpClient->request('POST', $baseUrl . $path, [
                    'json' => $payload,
                    'timeout' => 10,
                    'verify_peer' => $settings->isVerifyTls(),
                    'verify_host' => $settings->isVerifyTls(),
                ]);
            } catch (TransportExceptionInterface $exception) {
                $lastError = $exception;
                continue;
            }

            if ($response->getStatusCode() >= 400) {
                $lastError = new \RuntimeException('Auth failed.');
                continue;
            }

            $cookies = $this->extractCookies($response->getHeaders(false));
            if ($cookies !== '') {
                return $cookies;
            }

            $lastError = new \RuntimeException('Missing cookie.');
        }

        $code = $lastError instanceof TransportExceptionInterface && str_contains($lastError->getMessage(), 'SSL')
            ? 'tls_failed'
            : 'auth_failed';

        throw new UnifiApiException('UniFi authentication failed.', $code);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function extractCookies(array $headers): string
    {
        $cookies = $headers['set-cookie'] ?? $headers['Set-Cookie'] ?? [];
        if (is_string($cookies)) {
            $cookies = [$cookies];
        }
        if (!is_array($cookies)) {
            $cookies = [];
        }

        if ($cookies === []) {
            foreach ($headers as $headerValue) {
                if (is_string($headerValue) && str_starts_with(strtolower($headerValue), 'set-cookie:')) {
                    $cookies[] = trim(substr($headerValue, strlen('set-cookie:')));
                }
            }
        }

        if ($cookies === []) {
            return '';
        }

        $pairs = [];
        foreach ($cookies as $cookie) {
            $parts = explode(';', $cookie);
            if ($parts === [] || trim($parts[0]) === '') {
                continue;
            }
            $pairs[] = trim($parts[0]);
        }

        return implode('; ', $pairs);
    }

    /**
     * @param array{headers: array<string, list<string>>, status: int, content: string} $response
     * @return array<string, mixed>
     */
    private function decodeResponse(array $response): array
    {
        $decoded = json_decode($response['content'], true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>|null
     */
    private function normalizeRule(array $rule): ?array
    {
        $name = $rule['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }

        return [
            'id' => $rule['_id'] ?? $rule['id'] ?? null,
            'name' => $name,
            'port' => $rule['dst_port'] ?? $rule['port'] ?? null,
            'target_port' => $rule['fwd_port'] ?? $rule['target_port'] ?? null,
            'target_ip' => $rule['fwd'] ?? $rule['target_ip'] ?? null,
            'protocol' => $rule['proto'] ?? $rule['protocol'] ?? null,
            'enabled' => $rule['enabled'] ?? $rule['enable'] ?? true,
        ];
    }
}
