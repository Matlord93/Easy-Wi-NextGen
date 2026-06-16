<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Client;

use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class AgentGameServerClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EncryptionService $encryptionService,
        private readonly int $timeoutSeconds = 10,
        private readonly ?AgentHmacHeaderFactory $hmacHeaderFactory = null,
    ) {
    }

    /**
     * @param array<int, array{proto: string, port: int}> $checks
     * @return array<int, array{proto: string, port: int, free: bool}>
     */
    public function checkFreePorts(Instance $instance, array $checks): array
    {
        $response = $this->requestJson($instance, 'POST', '/ports/check-free', [
            'checks' => $checks,
        ]);

        return is_array($response['results'] ?? null) ? $response['results'] : [];
    }


    /**
     * @param array<string, mixed> $payload
     * @return array{content: string, content_type: string|null}
     */
    public function downloadInstanceBackup(Instance $instance, array $payload): array
    {
        $download = $this->openInstanceBackupDownload($instance, $payload);
        $content = '';
        foreach ($this->streamResponseContent($download['response']) as $chunk) {
            $content .= $chunk;
        }

        return [
            'content' => $content,
            'content_type' => $download['content_type'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{response: ResponseInterface, content_type: string|null}
     */
    public function openInstanceBackupDownload(Instance $instance, array $payload): array
    {
        $endpoint = sprintf('/v1/instances/%d/backups/download', (int) $instance->getId());
        $headers = $this->buildAuthHeaders($instance, 'POST', $endpoint);
        $headers['Accept'] = 'application/octet-stream';

        $response = $this->httpClient->request('POST', $this->resolveBaseUrl($instance->getNode()) . $endpoint, [
            'headers' => $headers,
            'json' => $payload,
            'timeout' => max($this->timeoutSeconds, 60),
            'max_duration' => max($this->timeoutSeconds, 3600),
        ]);

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            throw new \RuntimeException('Agent backup download endpoint unavailable.', previous: $exception);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response));
        }

        $headers = $response->getHeaders(false);

        return [
            'response' => $response,
            'content_type' => $headers['content-type'][0] ?? null,
        ];
    }

    /**
     * @return \Generator<int, string>
     */
    public function streamResponseContent(ResponseInterface $response): \Generator
    {
        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout() || $chunk->isFirst() || $chunk->isLast()) {
                continue;
            }

            yield $chunk->getContent();
        }
    }

    private function extractErrorMessage(ResponseInterface $response): string
    {
        $content = $response->getContent(false);
        $message = 'Agent backup download failed.';
        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            if (is_array($decoded) && is_string($decoded['message'] ?? null) && $decoded['message'] !== '') {
                $message = $decoded['message'];
            } elseif (is_array($decoded) && is_string($decoded['error'] ?? null) && $decoded['error'] !== '') {
                $message = $decoded['error'];
            }
        } catch (\JsonException) {
            if (trim($content) !== '') {
                $message = trim($content);
            }
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function renderConfig(Instance $instance, array $payload): array
    {
        return $this->requestJson($instance, 'POST', '/instance/render-config', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createFromMasterTemplate(Instance $instance, array $payload): array
    {
        return $this->requestJson($instance, 'POST', '/api/server/create', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function startInstance(Instance $instance, array $payload): array
    {
        return $this->requestJson($instance, 'POST', '/instance/start', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function stopInstance(Instance $instance, array $payload): array
    {
        return $this->requestJson($instance, 'POST', '/instance/stop', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInstanceStatus(Instance $instance): array
    {
        return $this->requestJson($instance, 'POST', '/instance/status', [
            'instance_id' => (string) $instance->getId(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInstanceAccessHealth(Instance $instance): array
    {
        return $this->requestJson($instance, 'GET', sprintf('/v1/instances/%d/access/health', (int) $instance->getId()), []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function provisionInstanceAccess(Instance $instance, array $payload): array
    {
        return $this->requestJson($instance, 'POST', sprintf('/v1/instances/%d/access/provision', (int) $instance->getId()), $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function resetInstanceAccess(Instance $instance, array $payload): array
    {
        return $this->requestJson($instance, 'POST', sprintf('/v1/instances/%d/access/reset', (int) $instance->getId()), $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccessCapabilities(Instance $instance): array
    {
        return $this->requestJson($instance, 'GET', '/v1/access/capabilities', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getConsoleLogs(Instance $instance, ?string $cursor = null): array
    {
        $payload = [];
        $cursor = trim((string) $cursor);
        if ($cursor !== '') {
            $payload['cursor'] = $cursor;
        }

        return $this->requestJson($instance, 'GET', sprintf('/v1/instances/%d/console/logs', (int) $instance->getId()), $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function applyInstanceConfig(Instance $instance, array $payload): array
    {
        return $this->requestJson($instance, 'POST', sprintf('/v1/instances/%d/configs/apply', (int) $instance->getId()), $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function requestJson(Instance $instance, string $method, string $endpoint, array $payload): array
    {
        $requestUri = $endpoint;
        if ($method === 'GET' && $payload !== []) {
            $requestUri .= '?' . http_build_query($payload, '', '&', \PHP_QUERY_RFC3986);
        }
        $body = '';
        if ($method !== 'GET') {
            $body = $this->encodeJsonBody($payload);
        }
        $headers = $this->buildAuthHeaders($instance, $method, $requestUri, $body);
        $headers['Accept'] = 'application/json';

        $options = [
            'headers' => $headers,
            'timeout' => $this->timeoutSeconds,
        ];
        if ($method !== 'GET') {
            $headers['Content-Type'] = 'application/json';
            $options['headers'] = $headers;
            $options['body'] = $body;
        } elseif ($payload !== []) {
            $options['query'] = $payload;
        }

        $response = $this->httpClient->request($method, $this->resolveBaseUrl($instance->getNode()) . $endpoint, $options);

        $content = $response->getContent(false);
        if (trim($content) === '') {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Agent response was not valid JSON.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Agent response was not a JSON object.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $payload */
    private function encodeJsonBody(array $payload): string
    {
        $body = json_encode($payload, \JSON_THROW_ON_ERROR);
        \assert(is_string($body));

        return $body;
    }

    /**
     * @return array<string, string>
     */
    private function buildAuthHeaders(Instance $instance, string $method, string $requestUri, string $body = ''): array
    {
        $headers = ($this->hmacHeaderFactory ?? new AgentHmacHeaderFactory($this->encryptionService))->create($instance, $method, $requestUri, $body);

        $metadata = $instance->getNode()->getMetadata();
        $metadata = is_array($metadata) ? $metadata : [];
        $bearerToken = trim((string) ($metadata['gamesvc_bearer_token'] ?? ''));
        if ($bearerToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $bearerToken;
        }

        return $headers;
    }

    private function resolveBaseUrl(Agent $node): string
    {
        $baseUrl = trim($node->getServiceBaseUrl());
        if ($baseUrl !== '') {
            return $this->normalizeBaseUrl($baseUrl);
        }

        $metadata = $node->getMetadata();
        $metadata = is_array($metadata) ? $metadata : [];

        $url = $metadata['gamesvc_url'] ?? null;
        if (is_string($url) && trim($url) !== '') {
            return $this->normalizeBaseUrl($url);
        }


        $heartbeatIp = trim((string) $node->getLastHeartbeatIp());
        $metadataScheme = $metadata['agent_service_scheme'] ?? null;
        $metadataPort = $metadata['agent_service_port'] ?? null;
        if ($heartbeatIp !== '' && is_numeric($metadataPort)) {
            $scheme = is_string($metadataScheme) && trim($metadataScheme) !== '' ? trim($metadataScheme) : 'https';
            return sprintf('%s://%s:%d', $scheme, $heartbeatIp, (int) $metadataPort);
        }

        $heartbeatIp = trim((string) $node->getLastHeartbeatIp());
        if ($heartbeatIp !== '') {
            return sprintf('https://%s', $heartbeatIp);
        }

        throw new \RuntimeException('Game service base URL not configured.');
    }

    private function normalizeBaseUrl(string $url): string
    {
        $trimmedUrl = trim($url);
        $parsed = parse_url($trimmedUrl);

        if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
            return rtrim($trimmedUrl, '/');
        }

        $normalized = sprintf('%s://%s', $parsed['scheme'], $parsed['host']);

        if (isset($parsed['port'])) {
            $normalized .= sprintf(':%d', (int) $parsed['port']);
        }

        $path = isset($parsed['path']) ? trim($parsed['path']) : '';
        if ($path !== '' && $path !== '/') {
            $normalized .= '/' . ltrim($path, '/');
        }

        return rtrim($normalized, '/');
    }
}
