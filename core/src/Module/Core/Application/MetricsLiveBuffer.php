<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
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
        $cpu = JsonArray::orEmpty($row['cpu'] ?? null);
        $memory = JsonArray::orEmpty($row['memory'] ?? null);
        $disk = JsonArray::orEmpty($row['disk'] ?? null);
        $network = JsonArray::orEmpty($row['net'] ?? null);

        $samples[] = [
            'ts' => $row['collected_at'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'cpu' => is_numeric($cpu['percent'] ?? null) ? (float) $cpu['percent'] : null,
            'mem' => is_numeric($memory['percent'] ?? null) ? (float) $memory['percent'] : null,
            'disk' => is_numeric($disk['percent'] ?? null) ? (float) $disk['percent'] : null,
            'net_sent' => is_numeric($network['bytes_sent'] ?? null) ? (int) $network['bytes_sent'] : null,
            'net_recv' => is_numeric($network['bytes_recv'] ?? null) ? (int) $network['bytes_recv'] : null,
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
        return self::sampleRows($this->cache->get($this->liveKey($agent), static function (ItemInterface $item): array {
            $item->expiresAfter(self::LIVE_TTL);
            return [];
        }));
    }

    /**
     * Return the timestamp of the last sample written to the DB for this agent,
     * or null when the cache is cold (triggers a DB fallback in ingestion service).
     */
    public function getLastWrittenAt(Agent $agent): ?\DateTimeImmutable
    {
        return self::writtenAt($this->cache->get($this->lastWrittenKey($agent), static function (ItemInterface $item): ?string {
            $item->expiresAfter(self::LAST_WRITTEN_TTL);
            return null;
        }));
    }

    private static function writtenAt(mixed $raw): ?\DateTimeImmutable
    {
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

    /** @return list<array<string, mixed>> */
    private static function sampleRows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach (array_keys($value) as $key) {
            $row = JsonMap::fromValue($value[$key]);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function liveKey(Agent $agent): string
    {
        return sprintf('metrics.live.%s', $agent->getId());
    }

    private function lastWrittenKey(Agent $agent): string
    {
        return sprintf('metrics.last_written.%s', $agent->getId());
    }
}
