<?php

declare(strict_types=1);

namespace App\Module\Voice\Application;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Domain\Entity\VoiceInstance;
use App\Module\Voice\Application\Provider\VoiceQueryResult;
use App\Module\Voice\Application\Query\ServerQueryLimiterInterface;
use App\Module\Voice\Application\Ts3\Ts3VirtualServerService;
use App\Module\Voice\Application\Ts6\Ts6VirtualServerService;
use App\Repository\AgentJobRepository;
use App\Repository\Ts3VirtualServerRepository;
use App\Repository\Ts6VirtualServerRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\Lock\LockFactory;

/** Shared orchestration; existing TS3 and TS6 payloads and protocols remain authoritative. */
final class TeamspeakServerOperations implements TeamspeakServerOperationsInterface
{
    public function __construct(
        private readonly Ts3VirtualServerRepository $ts3Servers,
        private readonly Ts6VirtualServerRepository $ts6Servers,
        private readonly Ts3VirtualServerService $ts3,
        private readonly Ts6VirtualServerService $ts6,
        private readonly AgentJobRepository $jobs,
        private readonly CacheInterface $cache,
        private readonly ServerQueryLimiterInterface $limiter,
        private readonly LockFactory $locks,
    ) {
    }

    #[\Override]
    public function resolve(VoiceInstance $instance): Ts3VirtualServer|Ts6VirtualServer
    {
        $id = filter_var($instance->getExternalId(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $server = $id === false ? null : match ($instance->getNode()->getProviderType()) {
            'ts3' => $this->ts3Servers->find($id),
            'ts6' => $this->ts6Servers->find($id),
            default => null,
        };

        // A panel-owned VoiceInstance is not authority to access another customer's external ID.
        if ($server === null || !$instance->getNode()->isEnabled() || $instance->getCustomer()->getId() === null
            || $server->getCustomerId() !== $instance->getCustomer()->getId()
            || $server->getArchivedAt() !== null
        ) {
            throw new \RuntimeException('Voice server binding is missing or inaccessible.');
        }

        return $server;
    }

    #[\Override]
    public function action(Ts3VirtualServer|Ts6VirtualServer $server, string $action): AgentJob
    {
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new \InvalidArgumentException('Unsupported voice action.');
        }
        if ($server->getArchivedAt() !== null || $server->getSid() <= 0) {
            throw new \RuntimeException('Voice server is not provisioned or has been archived.');
        }

        $lock = $this->locks->createLock('voice_action_' . $this->probeKey($server) . '_sid_' . $server->getSid());
        if (!$lock->acquire()) {
            throw new VoiceActionConflict(null);
        }
        try {
            $active = $this->activeAction($server);
            if ($active !== null) {
                if (($active->getPayload()['action'] ?? null) === $action) {
                    return $active;
                }
                throw new VoiceActionConflict($active->getId());
            }
            // The branches preserve the concrete service/entity contracts.
            $job = $server instanceof Ts3VirtualServer
            ? match ($action) {
                'start' => $this->ts3->start($server),
                'stop' => $this->ts3->stop($server),
                'restart' => $this->ts3->restart($server),
            }
            : match ($action) {
                'start' => $this->ts6->start($server),
                'stop' => $this->ts6->stop($server),
                'restart' => $this->ts6->restart($server),
            };
            $this->cache->delete($this->probeKey($server));

            return $job;
        } finally {
            $lock->release();
        }
    }

    #[\Override]
    public function activeAction(Ts3VirtualServer|Ts6VirtualServer $server): ?AgentJob
    {
        $provider = $server instanceof Ts3VirtualServer ? 'ts3' : 'ts6';
        foreach ($this->jobs->findBy([
            'node' => $server->getNode()->getAgent(),
            'type' => $provider . '.virtual.action',
            'status' => [AgentJobStatus::Queued, AgentJobStatus::Running],
        ], ['createdAt' => 'DESC']) as $job) {
            if ((int) ($job->getPayload()['virtual_server_id'] ?? 0) === $server->getId()) {
                return $job;
            }
        }

        return null;
    }

    #[\Override]
    public function probe(Ts3VirtualServer|Ts6VirtualServer $server): VoiceQueryResult
    {
        if ($server->getArchivedAt() !== null || $server->getSid() <= 0) {
            return $this->result('unknown', error: 'voice_instance_not_found');
        }

        $key = $this->probeKey($server);
        $jobId = $this->cache->get($key, function (ItemInterface $item) use ($server, $key): string {
            $item->expiresAfter(30);
            if (!$this->limiter->allow($key, 5, 30)->isAllowed()) {
                return '';
            }
            // serverlist also reports stopped servers; serverinfo alone cannot establish that state.
            $job = $server instanceof Ts3VirtualServer
                ? $this->ts3->queueVirtualServerSync($server->getNode())
                : $this->ts6->queueVirtualServerSync($server->getNode());

            return $job->getId();
        });
        if ($jobId === '') {
            $this->cache->delete($key);
            return $this->result('pending', error: 'voice_query_rate_limited');
        }

        $job = $this->jobs->find($jobId);
        $provider = $server instanceof Ts3VirtualServer ? 'ts3' : 'ts6';
        if ($job === null || $job->getNode()->getId() !== $server->getNode()->getAgent()->getId()
            || $job->getType() !== $provider . '.virtual.list'
            || (int) ($job->getPayload()['node_id'] ?? 0) !== $server->getNode()->getId()
        ) {
            $this->cache->delete($key);
            return $this->result('unknown', error: 'voice_query_failed');
        }
        if (in_array($job->getStatus(), [AgentJobStatus::Queued, AgentJobStatus::Running], true)) {
            return new VoiceQueryResult('pending', null, null, null, new \DateTimeImmutable(), null, null, $jobId);
        }
        if ($job->getStatus() !== AgentJobStatus::Success) {
            return $this->result('unknown', error: 'voice_query_failed', checkedAt: $job->getFinishedAt());
        }

        foreach (JsonArray::orEmpty($job->getResultPayload()['servers'] ?? null) as $row) {
            if (!is_array($row) || (int) ($row['sid'] ?? 0) !== $server->getSid()) {
                continue;
            }
            $status = strtolower(JsonMap::strings($row)['status'] ?? '');
            $status = match ($status) {
                'online', 'running', 'started', 'up' => 'online',
                'offline', 'stopped', 'down' => 'offline',
                default => 'unknown',
            };

            return $this->result(
                $status,
                $this->nonNegativeInt($row['players_online'] ?? null),
                ($max = $this->nonNegativeInt($row['slots'] ?? null)) !== null && $max > 0 ? $max : null,
                $status === 'unknown' ? 'voice_query_invalid_response' : null,
                $job->getFinishedAt(),
            );
        }

        return $this->result('unknown', error: 'voice_instance_not_found', checkedAt: $job->getFinishedAt());
    }

    private function probeKey(Ts3VirtualServer|Ts6VirtualServer $server): string
    {
        return sprintf('voice_query_%s_node_%d', $server instanceof Ts3VirtualServer ? 'ts3' : 'ts6', $server->getNode()->getId() ?? 0);
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $result === false ? null : $result;
    }

    private function result(string $status, ?int $players = null, ?int $max = null, ?string $error = null, ?\DateTimeImmutable $checkedAt = null): VoiceQueryResult
    {
        return new VoiceQueryResult($status, $players, $max, null, $checkedAt ?? new \DateTimeImmutable(), $error === null ? null : 'Voice query could not establish the current server status.', $error);
    }
}
