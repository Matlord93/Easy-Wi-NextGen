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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function requestJson(Instance $instance, string $method, string $endpoint, array $payload): array
    {
        $headers = $this->buildAuthHeaders($instance, $method, $endpoint);
        $headers['Accept'] = 'application/json';

        $response = $this->httpClient->request($method, $this->resolveBaseUrl($instance->getNode()) . $endpoint, [
            'headers' => $headers,
            'json' => $payload,
            'timeout' => $this->timeoutSeconds,
        ]);

        return $response->toArray(false);
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
        $metadata = $node->getMetadata();
        $metadata = is_array($metadata) ? $metadata : [];

        $url = $metadata['gamesvc_url'] ?? null;
        if (is_string($url) && $url !== '') {
            return rtrim($url, '/');
        }

        $host = $metadata['gamesvc_host'] ?? null;
        if (!is_string($host) || $host === '') {
            $host = $node->getLastHeartbeatIp();
        }
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('Game service host not configured.');
        }

        $port = $metadata['gamesvc_port'] ?? null;
        $port = is_numeric($port) ? (int) $port : 8444;
        $scheme = $metadata['gamesvc_scheme'] ?? null;
        $scheme = is_string($scheme) && $scheme !== '' ? $scheme : 'https';

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }
}
