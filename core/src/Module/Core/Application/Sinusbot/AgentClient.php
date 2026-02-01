<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Sinusbot;

use App\Module\Core\Application\AgentCredentialsResolver;
use App\Module\Core\Application\AgentEndpointResolver;
use App\Module\Core\Domain\Entity\SinusbotNode;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AgentClient
{
    private const HEADER_AGENT_ID = 'X-Agent-ID';
    private const HEADER_TIMESTAMP = 'X-Timestamp';
    private const HEADER_SIGNATURE = 'X-Signature';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AgentEndpointResolver $endpointResolver,
        private readonly AgentCredentialsResolver $credentialsResolver,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function requestJson(SinusbotNode $node, string $method, string $path, array $payload): array
    {
        $endpoint = $this->endpointResolver->resolveForNode($node);
        $credentials = $this->credentialsResolver->resolveForAgent($node->getAgent());

        $body = $payload !== [] ? json_encode($payload, JSON_THROW_ON_ERROR) : '';
        $url = $endpoint->getBaseUrl() . $path;
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeImmutable::RFC3339);
        $signature = $this->signPayload($credentials->getAgentId(), $method, $path, $timestamp, $body, $credentials->getSecret());

        $attempts = 0;
        do {
            $attempts++;
            try {
                $response = $this->httpClient->request($method, $url, [
                    'headers' => [
                        self::HEADER_AGENT_ID => $credentials->getAgentId(),
                        self::HEADER_TIMESTAMP => $timestamp,
                        self::HEADER_SIGNATURE => $signature,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => $body,
                    'timeout' => $this->timeoutSeconds,
                ]);

                $statusCode = $response->getStatusCode();
                $content = $response->getContent(false);
                $data = $content !== '' ? json_decode($content, true) : [];

                if ($statusCode >= 400) {
                    $errorCode = is_array($data) ? ($data['error_code'] ?? 'agent_error') : 'agent_error';
                    $message = is_array($data) ? ($data['error'] ?? 'Agent error') : 'Agent error';
                    throw new AgentBadResponseException(sprintf('%s (%s)', $message, (string) $errorCode));
                }

                return is_array($data) ? $data : [];
            } catch (TransportExceptionInterface $exception) {
                $this->logger->warning('Sinusbot agent request failed.', [
                    'path' => $path,
                    'attempt' => $attempts,
                    'error' => $exception->getMessage(),
                ]);
                if ($attempts >= 2) {
                    throw new AgentUnavailableException('Sinusbot agent unavailable.', previous: $exception);
                }
            }
        } while ($attempts < 2);

        return [];
    }

    private function signPayload(string $agentId, string $method, string $path, string $timestamp, string $body, string $secret): string
    {
        $payload = sprintf("%s\n%s\n%s\n%s\n%s", $agentId, strtoupper($method), $path, $timestamp, $body);

        return hash_hmac('sha256', $payload, $secret);
    }
}
