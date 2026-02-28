<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Grpc;

use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Gameserver\Application\Console\ConsoleAgentGrpcClientInterface;
use App\Module\Gameserver\Application\Console\ConsoleCommandRequest;
use App\Module\Gameserver\Application\Console\ConsoleCommandResult;
use App\Module\Gameserver\Application\Console\NodeEndpointMissingException;
use App\Repository\InstanceRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GrpcConsoleAgentGrpcClient implements ConsoleAgentGrpcClientInterface
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly HttpClientInterface $httpClient,
        private readonly SecretsCrypto $secretsCrypto,
    ) {
    }

    public function sendCommand(ConsoleCommandRequest $request): ConsoleCommandResult
    {
        $node = $this->resolveNode($request->instanceId);
        $endpoint = $this->resolveEndpoint($node);

        $response = $this->httpClient->request('POST', rtrim($endpoint, '/') . '/v1/console/command', [
            'headers' => $this->buildHeaders($node),
            'json' => [
                'instance_id' => $request->instanceId,
                'command' => $request->command,
                'idempotency_key' => $request->idempotencyKey,
                'issued_at_unix_ms' => $request->issuedAtUnixMs,
                'actor_id' => $request->actorId,
            ],
            'timeout' => 10,
        ]);

        $payload = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException((string) ($payload['message'] ?? 'Command dispatch failed.'));
        }

        return new ConsoleCommandResult(
            (bool) ($payload['applied'] ?? true),
            (bool) ($payload['duplicate'] ?? false),
            isset($payload['seq']) ? (int) $payload['seq'] : null,
        );
    }

    public function attachStream(int $instanceId): iterable
    {
        $node = $this->resolveNode($instanceId);
        $endpoint = $this->resolveEndpoint($node);

        $response = $this->httpClient->request('GET', rtrim($endpoint, '/') . '/v1/console/stream', [
            'headers' => $this->buildHeaders($node),
            'query' => ['instance_id' => $instanceId],
            'timeout' => 0,
        ]);

        $buffer = '';
        foreach ($this->httpClient->stream($response, 30.0) as $chunk) {
            if ($chunk->isTimeout()) {
                continue;
            }
            if ($chunk->isLast()) {
                break;
            }

            $buffer .= $chunk->getContent();
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    yield $decoded;
                }
            }
        }
    }

    private function resolveNode(int $instanceId): Agent
    {
        $instance = $this->instanceRepository->find($instanceId);
        if ($instance === null) {
            throw new \RuntimeException('Instance not found for console stream.');
        }

        return $instance->getNode();
    }

    private function resolveEndpoint(Agent $node): string
    {
        $metadata = $node->getMetadata() ?? [];
        $grpcEndpoint = trim((string) ($metadata['grpc_endpoint'] ?? ''));
        if ($grpcEndpoint !== '') {
            return rtrim($grpcEndpoint, '/');
        }

        $baseUrl = trim($node->getServiceBaseUrl());
        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/');
        }

        throw new NodeEndpointMissingException('node_endpoint_missing');
    }

    /** @return array<string,string> */
    private function buildHeaders(Agent $node): array
    {
        $headers = [
            'Accept' => 'application/json',
            'X-Agent-ID' => $node->getId(),
        ];

        $token = '';
        try {
            $token = trim($node->getServiceApiToken($this->secretsCrypto));
        } catch (\Throwable) {
            $token = '';
        }

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }
}
