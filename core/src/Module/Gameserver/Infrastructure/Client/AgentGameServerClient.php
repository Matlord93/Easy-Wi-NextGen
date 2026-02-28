<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Client;

use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AgentGameServerClient
{
    private const HEADER_AGENT_ID = 'x-agent-id';
    private const HEADER_CUSTOMER_ID = 'x-customer-id';
    private const HEADER_TIMESTAMP = 'x-agent-timestamp';
    private const HEADER_SIGNATURE = 'x-agent-signature';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EncryptionService $encryptionService,
        private readonly int $timeoutSeconds = 10,
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
     */
    public function renderConfig(Instance $instance, array $payload): array
    {
        return $this->requestJson($instance, 'POST', '/instance/render-config', $payload);
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
        $headers = $this->buildAuthHeaders($instance, $method, $endpoint);
        $headers['Accept'] = 'application/json';

        $options = [
            'headers' => $headers,
            'timeout' => $this->timeoutSeconds,
        ];
        if ($method !== 'GET') {
            $options['json'] = $payload;
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

    /**
     * @return array<string, string>
     */
    private function buildAuthHeaders(Instance $instance, string $method, string $endpoint): array
    {
        $agent = $instance->getNode();
        $agentId = $agent->getId();
        $customerId = (string) $instance->getCustomer()->getId();
        $secret = $this->encryptionService->decrypt($agent->getSecretPayload());
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeImmutable::RFC3339);

        $payload = sprintf(
            "%s\n%s\n%s\n%s\n%s",
            $agentId,
            $customerId,
            strtoupper($method),
            $endpoint,
            $timestamp,
        );
        $signature = hash_hmac('sha256', $payload, $secret);

        return [
            self::HEADER_AGENT_ID => $agentId,
            self::HEADER_CUSTOMER_ID => $customerId,
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_SIGNATURE => $signature,
        ];
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
