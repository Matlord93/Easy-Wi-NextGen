<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Grpc;

use App\Module\Core\Application\AgentConfigurationException;
use App\Module\Core\Application\AgentEndpointResolver;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Application\Console\ConsoleAgentGrpcClientInterface;
use App\Module\Gameserver\Application\Console\ConsoleCommandRequest;
use App\Module\Gameserver\Application\Console\ConsoleCommandResult;
use App\Module\Gameserver\Application\Console\ConsoleUnavailableException;
use App\Module\Gameserver\Application\Console\NodeEndpointMissingException;
use App\Module\Gameserver\Infrastructure\Client\AgentHmacHeaderFactory;
use App\Repository\InstanceRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GrpcConsoleAgentGrpcClient implements ConsoleAgentGrpcClientInterface
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly HttpClientInterface $httpClient,
        private readonly AgentHmacHeaderFactory $hmacHeaderFactory,
        private readonly AgentEndpointResolver $endpointResolver,
    ) {
    }

    public function sendCommand(ConsoleCommandRequest $request): ConsoleCommandResult
    {
        $instance = $this->resolveInstance($request->instanceId);
        $node = $instance->getNode();
        $endpoint = $this->resolveEndpoint($node);
        $requestUri = '/v1/instances/' . $request->instanceId . '/console/command';

        $response = $this->httpClient->request('POST', rtrim($endpoint, '/') . $requestUri, [
            'headers' => $this->buildHeaders($instance, 'POST', $requestUri),
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
            $message = (string) ($payload['message'] ?? 'Command dispatch failed.');
            $errorCode = (string) ($payload['error_code'] ?? '');
            // 409 means the agent is reachable but the console is definitively
            // unavailable (socket missing, permission denied, …). Raise a typed
            // exception so the controller can return an error to the user instead
            // of silently queueing a fallback job that would also fail.
            if ($response->getStatusCode() === 409) {
                throw new ConsoleUnavailableException($message, $errorCode !== '' ? $errorCode : 'CONSOLE_UNAVAILABLE');
            }
            throw new \RuntimeException($message);
        }

        return new ConsoleCommandResult(
            (bool) ($payload['applied'] ?? true),
            (bool) ($payload['duplicate'] ?? false),
            isset($payload['seq']) ? (int) $payload['seq'] : null,
        );
    }

    public function attachStream(int $instanceId): iterable
    {
        $instance = $this->resolveInstance($instanceId);
        $node = $instance->getNode();
        $endpoint = $this->resolveEndpoint($node);
        $baseRequestUri = '/v1/instances/' . $instanceId . '/console/logs';
        $cursor = '';
        $emptyStreak = 0;

        while (true) {
            $query = $cursor !== '' ? ['cursor' => $cursor] : [];
            $requestUri = $this->withQueryString($baseRequestUri, $query);
            $response = $this->httpClient->request('GET', rtrim($endpoint, '/') . $requestUri, [
                'headers' => $this->buildHeaders($instance, 'GET', $requestUri),
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

    /** @return array<string,mixed> */
    public function getConsoleHealth(int $instanceId): array
    {
        $instance = $this->resolveInstance($instanceId);
        $node = $instance->getNode();
        $endpoint = $this->resolveEndpoint($node);
        $requestUri = '/v1/instances/' . $instanceId . '/console/health';

        $response = $this->httpClient->request(
            'GET',
            rtrim($endpoint, '/') . $requestUri,
            ['headers' => $this->buildHeaders($instance, 'GET', $requestUri), 'timeout' => 8],
        );

        return $response->toArray(false);
    }

    /**
     * @return array<string,mixed>  Raw agent response (data.cursor, data.lines, data.meta)
     */
    public function getConsoleLogs(int $instanceId, string $cursor = ''): array
    {
        $instance = $this->resolveInstance($instanceId);
        $node = $instance->getNode();
        $endpoint = $this->resolveEndpoint($node);

        $query = $cursor !== '' ? ['cursor' => $cursor] : [];
        $baseRequestUri = '/v1/instances/' . $instanceId . '/console/logs';
        $requestUri = $this->withQueryString($baseRequestUri, $query);

        $response = $this->httpClient->request(
            'GET',
            rtrim($endpoint, '/') . $requestUri,
            ['headers' => $this->buildHeaders($instance, 'GET', $requestUri), 'timeout' => 8],
        );

        return $response->toArray(false);
    }

    private function resolveInstance(int $instanceId): Instance
    {
        $instance = $this->instanceRepository->find($instanceId);
        if (!$instance instanceof Instance) {
            throw new \RuntimeException('Instance not found for console stream.');
        }

        return $instance;
    }

    private function resolveEndpoint(Agent $node): string
    {
        $metadata = $node->getMetadata() ?? [];
        $grpcEndpoint = trim((string) ($metadata['grpc_endpoint'] ?? ''));
        if ($grpcEndpoint !== '') {
            return rtrim($grpcEndpoint, '/');
        }

        try {
            return rtrim($this->endpointResolver->resolveForAgent($node)->getBaseUrl(), '/');
        } catch (AgentConfigurationException) {
            throw new NodeEndpointMissingException('node_endpoint_missing');
        }
    }

    /** @param array<string,string> $query */
    private function withQueryString(string $path, array $query): string
    {
        if ($query === []) {
            return $path;
        }

        return $path . '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }

    /** @return array<string,string> */
    private function buildHeaders(Instance $instance, string $method, string $requestUri): array
    {
        $headers = $this->hmacHeaderFactory->create($instance, $method, $requestUri);
        $headers['Accept'] = 'application/json';

        return $headers;
    }
}
