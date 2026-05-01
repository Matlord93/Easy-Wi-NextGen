<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Grpc;

use App\Module\Core\Application\AgentConfigurationException;
use App\Module\Core\Application\AgentEndpointResolver;
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
        private readonly AgentEndpointResolver $endpointResolver,
    ) {
    }

    public function sendCommand(ConsoleCommandRequest $request): ConsoleCommandResult
    {
        $node = $this->resolveNode($request->instanceId);
        $endpoint = $this->resolveEndpoint($node);

        $response = $this->httpClient->request('POST', rtrim($endpoint, '/') . '/v1/instances/' . $request->instanceId . '/console/command', [
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
        $headers = $this->buildHeaders($node);
        $url = rtrim($endpoint, '/') . '/v1/instances/' . $instanceId . '/console/logs';

        $cursor = '';
        $emptyStreak = 0;

        while (true) {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers,
                'query' => $cursor !== '' ? ['cursor' => $cursor] : [],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() >= 400) {
                throw new \RuntimeException('Agent console logs returned HTTP ' . $response->getStatusCode());
            }

            $payload = $response->toArray(false);
            $data = $payload['data'] ?? [];
            $newCursor = (string) ($data['cursor'] ?? '');
            $lines = (array) ($data['lines'] ?? []);

            if ($newCursor !== '') {
                $cursor = $newCursor;
            }

            foreach ($lines as $line) {
                yield [
                    'chunk' => (string) ($line['text'] ?? ''),
                    'ts' => (string) ($line['ts'] ?? (new \DateTimeImmutable())->format(DATE_ATOM)),
                    'seq' => isset($line['id']) ? (int) $line['id'] : null,
                    'status' => isset($line['level']) && $line['level'] !== '' ? (string) $line['level'] : null,
                ];
            }

            if (empty($lines)) {
                $emptyStreak++;
                usleep(min(2_000_000, 100_000 + ($emptyStreak * 100_000)));
            } else {
                $emptyStreak = 0;
                usleep(50_000);
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

        try {
            return rtrim((string) $this->endpointResolver->resolveForAgent($node), '/');
        } catch (AgentConfigurationException) {
            throw new NodeEndpointMissingException('node_endpoint_missing');
        }
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
