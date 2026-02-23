<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Application\InstanceQueryService;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;

final class QuerySmokeService implements QuerySmokeRunnerInterface
{
    public function __construct(
        private readonly InstanceQueryService $instanceQueryService,
        private readonly PortBlockRepository $portBlockRepository,
        private readonly TemplateEngineFamilyResolver $engineFamilyResolver,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function run(Instance $instance, bool $retryOnTimeout = true): array
    {
        $attempts = [];
        $attempt = $this->runAttempt($instance, 1);
        $attempts[] = $attempt;

        if ($retryOnTimeout && ($attempt['error_code'] ?? null) === 'QUERY_TIMEOUT') {
            $attempts[] = $this->runAttempt($instance, 2);
        }

        $final = $attempts[count($attempts) - 1];

        return [
            'engine' => $this->engineFamilyResolver->resolve($instance->getTemplate()),
            'instance_id' => $instance->getId(),
            'game' => $instance->getTemplate()->getGameKey(),
            'ok' => (bool) ($final['ok'] ?? false),
            'players' => $final['players'] ?? null,
            'max_players' => $final['max_players'] ?? null,
            'map' => $final['map'] ?? null,
            'latency_ms' => $final['latency_ms'] ?? null,
            'request_id' => $final['request_id'] ?? '',
            'debug' => $final['debug'] ?? [],
            'attempts' => $attempts,
            'error_code' => $final['error_code'] ?? null,
            'error_message' => $final['error_message'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function runAttempt(Instance $instance, int $attempt): array
    {
        $portBlock = $this->portBlockRepository->findByInstance($instance);
        $requestId = sprintf('query-smoke-%d-%d-%d', (int) ($instance->getId() ?? 0), $attempt, time());

        try {
            $spec = $this->instanceQueryService->resolveQuerySpec($instance, $portBlock);
        } catch (\Throwable $e) {
            return [
                'attempt' => $attempt,
                'ok' => false,
                'error_code' => 'INVALID_INSTANCE_HOST',
                'error_message' => $e->getMessage(),
                'request_id' => $requestId,
                'debug' => [],
            ];
        }

        $snapshot = $this->instanceQueryService->getSnapshot($instance, $portBlock, true);
        $result = is_array($snapshot['result'] ?? null) ? $snapshot['result'] : [];
        $reported = is_array($result['reported'] ?? null) ? $result['reported'] : [];
        $errorMessage = is_string($result['error'] ?? null) ? (string) $result['error'] : null;
        $ok = $errorMessage === null && (($result['online'] ?? null) !== false);

        return [
            'attempt' => $attempt,
            'ok' => $ok,
            'players' => isset($reported['players']) && is_numeric($reported['players']) ? (int) $reported['players'] : null,
            'max_players' => isset($reported['max_players']) && is_numeric($reported['max_players']) ? (int) $reported['max_players'] : null,
            'map' => is_string($reported['map'] ?? null) ? (string) $reported['map'] : null,
            'latency_ms' => isset($result['latency_ms']) && is_numeric($result['latency_ms']) ? (int) $result['latency_ms'] : null,
            'error_code' => $this->resolveErrorCode($errorMessage),
            'error_message' => $errorMessage,
            'request_id' => $requestId,
            'debug' => [
                'resolved_host' => $spec->getHost(),
                'resolved_port' => $spec->getPort(),
                'resolved_protocol' => $spec->getType(),
                'timeout_ms' => $spec->getTimeoutMs(),
                'host_source' => $spec->getExtra()['resolved_host_source'] ?? null,
                'port_source' => $spec->getExtra()['resolved_port_source'] ?? null,
                'last_error_code' => $this->resolveErrorCode($errorMessage),
                'last_error_message' => $errorMessage,
            ],
        ];
    }

    private function resolveErrorCode(?string $message): ?string
    {
        $error = strtolower(trim((string) $message));
        if ($error === '') {
            return null;
        }
        if (str_contains($error, 'timeout')) {
            return 'QUERY_TIMEOUT';
        }
        if (str_contains($error, 'connection refused')) {
            return 'CONNECTION_REFUSED';
        }

        return 'INSTANCE_OFFLINE';
    }
}
