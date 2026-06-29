<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\PublicServer;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;

final class PublicServerStatusService
{
    public const JOB_TYPE = 'server.status.check';
    public const MIN_REFRESH_INTERVAL_SECONDS = 15;

    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param iterable<PublicServer> $servers
     */
    public function queueDueChecks(iterable $servers, int $batchSize = 50, ?\DateTimeImmutable $now = null, bool $force = false): int
    {
        $now ??= new \DateTimeImmutable();
        $queued = 0;

        foreach ($servers as $server) {
            if ($queued >= $batchSize) {
                break;
            }

            if (!$force && !$this->isDue($server, $now)) {
                continue;
            }

            if ($this->queueCheck($server, $now, $force)) {
                $queued++;
            }
        }

        if ($queued > 0) {
            $this->entityManager->flush();
        }

        return $queued;
    }

    public function queueCheck(PublicServer $server, ?\DateTimeImmutable $now = null, bool $force = false): bool
    {
        $now ??= new \DateTimeImmutable();
        $serverId = (string) ($server->getId() ?? '');
        if ($serverId === '') {
            return false;
        }

        if (!$force && !$this->isDue($server, $now)) {
            return false;
        }

        if ($this->jobRepository->findActiveByTypeAndPayloadField(self::JOB_TYPE, 'server_id', $serverId) !== null) {
            return false;
        }

        $this->entityManager->persist(new Job(self::JOB_TYPE, $this->buildPayload($server)));
        $server->setNextCheckAt($now->modify(sprintf('+%d seconds', max(self::MIN_REFRESH_INTERVAL_SECONDS, $server->getCheckIntervalSeconds()))));
        $this->markChecking($server, $now);
        $this->entityManager->persist($server);

        return true;
    }

    public function isDue(PublicServer $server, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $last = $server->getLastCheckedAt();
        if ($last instanceof \DateTimeImmutable && ($now->getTimestamp() - $last->getTimestamp()) < self::MIN_REFRESH_INTERVAL_SECONDS) {
            return false;
        }

        $next = $server->getNextCheckAt();
        return !$next instanceof \DateTimeImmutable || $next <= $now;
    }

    public function applyResult(PublicServer $server, array $output, bool $succeeded, \DateTimeImmutable $checkedAt): void
    {
        $online = $succeeded && $this->normalizeOnline($output['online'] ?? $output['status'] ?? true);
        $cache = $server->getStatusCache();
        $cache['online'] = $online;
        $cache['status'] = $online ? 'online' : 'offline';
        $cache['players'] = is_numeric($output['players'] ?? null) ? (int) $output['players'] : null;
        $cache['max_players'] = is_numeric($output['max_players'] ?? null) ? (int) $output['max_players'] : null;
        $cache['map'] = is_string($output['map'] ?? null) && $output['map'] !== '' ? $output['map'] : null;
        $cache['name'] = is_string($output['name'] ?? null) && $output['name'] !== '' ? $output['name'] : null;
        $cache['last_error'] = $online ? null : $this->normalizeError($output);
        $cache['query_duration_ms'] = is_numeric($output['query_duration_ms'] ?? $output['duration_ms'] ?? null) ? (int) ($output['query_duration_ms'] ?? $output['duration_ms']) : null;
        $cache['last_checked_at'] = $checkedAt->format(DATE_RFC3339);
        $cache['checked_at'] = $cache['last_checked_at'];

        $server->setStatusCache($cache);
        $server->setLastCheckedAt($checkedAt);
    }

    public function toStatusPayload(PublicServer $server): array
    {
        $cache = $server->getStatusCache();
        $online = $this->normalizeOnline($cache['online'] ?? $cache['status'] ?? null);

        return [
            'id' => (string) ($server->getId() ?? ''),
            'online' => $online,
            'status' => $online ? 'online' : (($cache['status'] ?? null) === 'checking' ? 'checking' : 'offline'),
            'players' => is_numeric($cache['players'] ?? null) ? (int) $cache['players'] : null,
            'max_players' => is_numeric($cache['max_players'] ?? null) ? (int) $cache['max_players'] : null,
            'map' => is_string($cache['map'] ?? null) ? $cache['map'] : null,
            'name' => is_string($cache['name'] ?? null) ? $cache['name'] : null,
            'last_checked_at' => $server->getLastCheckedAt()?->format(DATE_RFC3339),
            'last_error' => is_string($cache['last_error'] ?? null) ? $cache['last_error'] : null,
            'query_duration_ms' => is_numeric($cache['query_duration_ms'] ?? null) ? (int) $cache['query_duration_ms'] : null,
        ];
    }

    private function buildPayload(PublicServer $server): array
    {
        $payload = ['server_id' => (string) $server->getId(), 'ip' => $server->getIp(), 'port' => (string) $server->getPort(), 'query_type' => $server->getQueryType(), 'game_key' => $server->getGameKey(), 'timeout_seconds' => '3'];
        if ($server->getQueryPort() !== null) {
            $payload['query_port'] = (string) $server->getQueryPort();
        }
        return $payload;
    }

    private function markChecking(PublicServer $server, \DateTimeImmutable $now): void
    {
        $cache = $server->getStatusCache();
        $cache['status'] = 'checking';
        $cache['queued_at'] = $now->format(DATE_RFC3339);
        $server->setStatusCache($cache);
    }

    private function normalizeOnline(mixed $value): bool
    {
        if ($value === true) { return true; }
        if (is_string($value)) { return in_array(strtolower($value), ['running', 'up', 'alive', 'ok', 'online', 'success', 'reachable'], true); }
        return false;
    }

    private function normalizeError(array $output): string
    {
        foreach (['last_error', 'error', 'message', 'reason'] as $key) {
            if (is_string($output[$key] ?? null) && trim($output[$key]) !== '') {
                return mb_substr(trim($output[$key]), 0, 240);
            }
        }
        return 'Query fehlgeschlagen oder Server nicht erreichbar.';
    }
}
