<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Api;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Application\Status\HmacRequestValidator;
use App\Module\Gameserver\Application\Status\StatusBatchUpdater;
use App\Repository\InstanceRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;

#[Route(path: '/api')]
final class ServerStatusApiController
{
    public function __construct(
        private readonly HmacRequestValidator $validator,
        private readonly StatusBatchUpdater $statusBatchUpdater,
        private readonly InstanceRepository $instanceRepository,
        private readonly CacheInterface $cache,
        private readonly int $staleGraceSeconds,
    ) {
    }

    #[Route(path: '/agent/status-batch', name: 'api_agent_status_batch', methods: ['POST'])]
    public function statusBatch(Request $request): JsonResponse
    {
        $body = (string) $request->getContent();
        if (!$this->validator->isValid($body, $request->headers->get('X-Timestamp'), $request->headers->get('X-Signature'))) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload) || !is_array($payload['items'] ?? null)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        $updated = $this->statusBatchUpdater->apply($payload['items']);
        $this->cache->delete('servers_status_all');

        return new JsonResponse(['ok' => true, 'updated' => $updated]);
    }

    #[Route(path: '/servers/status', name: 'api_servers_status', methods: ['GET'])]
    public function listStatus(): JsonResponse
    {
        $data = $this->cache->get('servers_status_all', function (\Symfony\Contracts\Cache\ItemInterface $item): array {
            $item->expiresAfter(300);
            return array_map(fn (Instance $instance) => $this->normalizeInstance($instance), $this->instanceRepository->findAll());
        });

        return new JsonResponse(['items' => $data]);
    }

    #[Route(path: '/agent/servers', name: 'api_agent_servers', methods: ['GET'])]
    public function agentServers(): JsonResponse
    {
        $rows = [];
        foreach ($this->instanceRepository->findAll() as $instance) {
            $cache = $instance->getQueryStatusCache();
            $queryType = (string) ($instance->getTemplate()->getRequirements()['query']['type'] ?? $instance->getTemplate()->getRequirements()['query_type'] ?? 'steam_a2s');
            $rows[] = [
                'server_id' => $instance->getId(),
                'host' => $instance->getNode()->getLastHeartbeatIp(),
                'port' => $instance->getAssignedPort(),
                'type' => $queryType,
                'poll_interval_seconds' => is_numeric($cache['poll_interval_seconds'] ?? null) ? (int) $cache['poll_interval_seconds'] : 15,
            ];
        }

        return new JsonResponse(['items' => $rows]);
    }

    private function normalizeInstance(Instance $instance): array
    {
        $cache = $instance->getQueryStatusCache();
        $checkedAt = $instance->getQueryCheckedAt();
        $isStale = $this->isStale($checkedAt, is_numeric($cache['poll_interval_seconds'] ?? null) ? (int) $cache['poll_interval_seconds'] : 15);
        $status = is_string($cache['status'] ?? null) ? strtolower((string) $cache['status']) : 'offline';
        if ($isStale) {
            $status = 'offline_stale';
        }

        return [
            'server_id' => $instance->getId(),
            'name' => $instance->getServerName() ?: $instance->getTemplate()->getDisplayName(),
            'status' => $status,
            'reachable' => (bool) ($cache['reachable'] ?? false),
            'players_online' => is_numeric($cache['players'] ?? null) ? (int) $cache['players'] : null,
            'players_max' => is_numeric($cache['max_players'] ?? null) ? (int) $cache['max_players'] : null,
            'map' => is_string($cache['map'] ?? null) ? $cache['map'] : null,
            'observed_at' => $checkedAt?->format(DATE_ATOM),
            'latency_ms' => is_numeric($cache['latency_ms'] ?? null) ? (int) $cache['latency_ms'] : null,
            'error' => is_string($cache['error'] ?? null) ? $cache['error'] : null,
        ];
    }

    private function isStale(?\DateTimeImmutable $observedAt, int $pollInterval): bool
    {
        if ($observedAt === null) {
            return true;
        }

        $maxAge = max(5, (2 * $pollInterval) + $this->staleGraceSeconds);

        return $observedAt->getTimestamp() < (time() - $maxAge);
    }
}
