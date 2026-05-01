<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Redis-backed live metrics buffer.
 *
 * Stores the most recent metric snapshots per agent in cache (Redis) so the
 * admin dashboard can display real-time data without hitting the database on
 * every heartbeat. Historical persistence is handled separately by
 * AgentMetricsIngestionService (5-minute DB write window).
 */
final class MetricsLiveBuffer
{
    private const MAX_SAMPLES = 60;
    private const LIVE_TTL = 300;
    private const LAST_WRITTEN_TTL = 600;

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Push one raw metric row into the ring buffer for the given agent.
     *
     * @param array<string,mixed> $row
     */
    public function push(Agent $agent, array $row): void
    {
        $key = $this->liveKey($agent);
        $samples = $this->fetchSamples($agent);

        $samples[] = [
            'ts' => $row['collected_at'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'cpu' => is_numeric($row['cpu']['percent'] ?? null) ? (float) $row['cpu']['percent'] : null,
            'mem' => is_numeric($row['memory']['percent'] ?? null) ? (float) $row['memory']['percent'] : null,
            'disk' => is_numeric($row['disk']['percent'] ?? null) ? (float) $row['disk']['percent'] : null,
            'net_sent' => is_numeric($row['net']['bytes_sent'] ?? null) ? (int) $row['net']['bytes_sent'] : null,
            'net_recv' => is_numeric($row['net']['bytes_recv'] ?? null) ? (int) $row['net']['bytes_recv'] : null,
        ];

        if (count($samples) > self::MAX_SAMPLES) {
            $samples = array_slice($samples, -self::MAX_SAMPLES);
        }

        $this->cache->delete($key);
        $this->cache->get($key, static function (ItemInterface $item) use ($samples): array {
            $item->expiresAfter(self::LIVE_TTL);
            return $samples;
        });
    }

    /**
     * Return all buffered live samples for the agent (newest last).
     *
     * @return list<array<string,mixed>>
     */
    public function fetchSamples(Agent $agent): array
    {
        $result = $this->cache->get($this->liveKey($agent), static function (ItemInterface $item): array {
            $item->expiresAfter(self::LIVE_TTL);
            return [];
        });
        return is_array($result) ? array_values($result) : [];
    }

    /**
     * Return the timestamp of the last sample written to the DB for this agent,
     * or null when the cache is cold (triggers a DB fallback in ingestion service).
     */
    public function getLastWrittenAt(Agent $agent): ?\DateTimeImmutable
    {
        $raw = $this->cache->get($this->lastWrittenKey($agent), static function (ItemInterface $item): ?string {
            $item->expiresAfter(self::LAST_WRITTEN_TTL);
            return null;
        });

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Record that a DB sample was just written for this agent.
     */
    public function markWritten(Agent $agent, \DateTimeImmutable $at): void
    {
        $key = $this->lastWrittenKey($agent);
        $raw = $at->format(\DateTimeInterface::ATOM);
        $this->cache->delete($key);
        $this->cache->get($key, static function (ItemInterface $item) use ($raw): string {
            $item->expiresAfter(self::LAST_WRITTEN_TTL);
            return $raw;
        });
    }

    private function liveKey(Agent $agent): string
    {
        return sprintf('metrics.live.%s', (string) $agent->getId());
    }

    private function lastWrittenKey(Agent $agent): string
    {
        return sprintf('metrics.last_written.%s', (string) $agent->getId());
    }
}
