<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Monitoring;

use App\Module\Nodes\Application\InfrastructureUsage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Exception\RetryableException;

/** Minute samples are independent of browser sessions. Never substitute missing values. */
final readonly class InfrastructureHistoryStore
{
    public const LOAD = ['cpu_percent', 'memory_percent', 'disk_percent', 'memory_used_bytes', 'memory_total_bytes', 'disk_used_bytes', 'disk_total_bytes'];
    public const IO = ['net_rx_bytes_per_second', 'net_tx_bytes_per_second', 'disk_read_bytes_per_second', 'disk_write_bytes_per_second'];

    public function __construct(private Connection $db, private InfrastructureUsage $usage)
    {
    }

    public function record(InfrastructureTarget $target, array $input): int
    {
        $data = $this->usage->normalize($input);
        $written = 0;
        foreach (['load' => self::LOAD, 'io' => self::IO] as $stream => $fields) {
            $timestamp = 'load' === $stream ? $data['observed_at'] : $data['io_observed_at'];
            $stale = 'load' === $stream ? $data['stale'] : $data['io_stale'];
            if (!$timestamp || $stale) {
                continue;
            }
            $values = array_intersect_key($data, array_flip($fields));
            if (!array_filter($values, static fn ($v): bool => null !== $v)) {
                continue;
            }
            $values['source'] = 'load' === $stream ? $data['load_source'] : $data['io_source'];
            $values['memory_source'] = 'load' === $stream ? $data['memory_source'] : null;
            $values['interval_seconds'] = 'io' === $stream ? $data['io_interval_seconds'] : null;
            $observed = (new \DateTimeImmutable($timestamp))->getTimestamp();
            if (null !== $target->visibleSince && ($observed <= $target->visibleSince || ('io' === $stream && $observed - ($data['io_interval_seconds'] ?? 0) <= $target->visibleSince))) {
                continue;
            }
            $written += (int) $this->write($target->key, $stream, $observed, $values);
        }

        return $written;
    }

    /** At most 1200 aggregate points, even for a year-long request. */
    public function history(InfrastructureTarget $target, int $from, int $to): array
    {
        if ($from < 0 || $to <= $from || $to - $from > 366 * 86400 || $to > time() + 30) {
            throw new \InvalidArgumentException('monitor.invalid_range');
        }
        $long = $to - $from > 86400;
        $resolution = $long ? max(3600, (int) ceil(($to - $from) / 599 / 3600) * 3600) : max(60, (int) ceil(($to - $from) / 600 / 60) * 60);
        $effectiveFrom = max($from, ($target->visibleSince ?? -1) + 1);
        $groups = [];
        $append = static function (string $stream, array $point) use (&$groups, $from, $resolution, $long): void {
            if (0 === $point['samples']) {
                return;
            }
            $observed = $point['first_observed_at'];
            $bucket = $long ? max($from, intdiv($observed, $resolution) * $resolution) : intdiv($observed - $from, $resolution) * $resolution + $from;
            $id = $stream.':'.$bucket;
            $groups[$id] = InfrastructureAggregation::merge($groups[$id] ?? InfrastructureAggregation::empty(), $point) + ['stream' => $stream, 'timestamp' => $bucket, 'resolution_seconds' => $resolution];
        };
        $completeFrom = intdiv($effectiveFrom + 3599, 3600) * 3600;
        $completeTo = intdiv($to, 3600) * 3600;
        if (!$long || $completeFrom >= $completeTo) {
            foreach ($this->raw($target->key, $effectiveFrom, $to) as $row) {
                $append($row['stream'], InfrastructureAggregation::sample($row['stream'], (int) $row['observed_at'], json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR)));
            }
        } else {
            foreach (['load', 'io'] as $stream) {
                $ranges = $effectiveFrom < $completeFrom ? [[$effectiveFrom, $completeFrom]] : [];
                $next = $completeFrom;
                foreach ($this->rows('SELECT bucket, payload FROM infrastructure_rollups WHERE resource_key = ? AND stream = ? AND bucket >= ? AND bucket < ? ORDER BY bucket', [$target->key, $stream, $completeFrom, $completeTo]) as $row) {
                    $hour = (int) $row['bucket'];
                    if ($next < $hour) {
                        $ranges[] = [$next, $hour];
                    }
                    $next = $hour + 3600;
                    $append($stream, json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR));
                }
                if ($next < $completeTo) {
                    $ranges[] = [$next, $completeTo];
                }
                if ($completeTo < $to) {
                    $ranges[] = [$completeTo, $to];
                }
                // Missing archives fall back to the untouched raw rows. Merge adjacent
                // gaps so an existing year without archives does not cause 8760 queries.
                $merged = [];
                foreach ($ranges as [$start, $end]) {
                    $last = array_key_last($merged);
                    if (null !== $last && $merged[$last][1] === $start) {
                        $merged[$last][1] = $end;
                    } else {
                        $merged[] = [$start, $end];
                    }
                }
                foreach ($merged as [$start, $end]) {
                    foreach ($this->raw($target->key, $start, $end, $stream) as $row) {
                        $append($stream, InfrastructureAggregation::sample($stream, (int) $row['observed_at'], json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR)));
                    }
                }
            }
        }
        $points = array_values($groups);
        usort($points, static fn (array $a, array $b): int => [$a['timestamp'], $a['stream']] <=> [$b['timestamp'], $b['stream']]);

        return ['from' => $from, 'to' => $to, 'resolution_seconds' => $resolution, 'points' => $points];
    }

    public function prune(int $days, ?\DateTimeImmutable $now = null): int
    {
        if ($days < 1 || $days > 365) {
            throw new \InvalidArgumentException('monitor.invalid_retention');
        }
        $cutoff = ($now ?? new \DateTimeImmutable())->getTimestamp() - $days * 86400;

        $removed = 0;
        foreach (['infrastructure_samples', 'infrastructure_rollups'] as $table) {
            $removed += $this->db->executeStatement('DELETE FROM '.$table.' WHERE (resource_key, stream, bucket) IN (SELECT resource_key, stream, bucket FROM (SELECT resource_key, stream, bucket FROM '.$table.' WHERE bucket < ? ORDER BY bucket LIMIT 10000) expired_samples)', [$cutoff]);
        }

        return $removed;
    }

    /** Bounded, one-time conversion of older raw history; raw data is retained. */
    public function backfill(int $limit = 100, ?\DateTimeImmutable $now = null): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('monitor.invalid_settings');
        }
        $closed = intdiv(($now ?? new \DateTimeImmutable())->getTimestamp() - 600, 3600) * 3600;
        $candidates = $this->db->fetchAllAssociative('SELECT DISTINCT s.resource_key, s.stream, (s.bucket - s.bucket % 3600) AS hour_bucket FROM infrastructure_samples s WHERE s.bucket < ? AND NOT EXISTS (SELECT 1 FROM infrastructure_rollups r WHERE r.resource_key = s.resource_key AND r.stream = s.stream AND r.bucket = s.bucket - s.bucket % 3600) ORDER BY hour_bucket LIMIT '.$limit, [$closed]);
        $created = 0;
        foreach ($candidates as $candidate) {
            $this->db->beginTransaction();
            try {
                $key = [$candidate['resource_key'], $candidate['stream'], (int) $candidate['hour_bucket']];
                if (!$this->db->fetchOne('SELECT 1 FROM infrastructure_rollups WHERE resource_key = ? AND stream = ? AND bucket = ?', $key)) {
                    $summary = $this->hour($key[0], $key[1], $key[2]);
                    if ($summary['samples'] > 0) {
                        $this->db->insert('infrastructure_rollups', ['resource_key' => $key[0], 'stream' => $key[1], 'bucket' => $key[2], 'payload' => json_encode($summary, JSON_THROW_ON_ERROR)]);
                        ++$created;
                    }
                }
                $this->db->commit();
            } catch (UniqueConstraintViolationException|RetryableException) {
                $this->db->rollBack();
                // Another collector can win this hour; never mark the entire scan done.
            } catch (\Throwable $exception) {
                $this->db->rollBack();
                throw $exception;
            }
        }

        return ['created' => $created, 'pending' => [] !== $candidates];
    }

    private function write(string $resource, string $stream, int $observed, array $payload): bool
    {
        $minute = intdiv($observed, 60) * 60;
        $hour = intdiv($observed, 3600) * 3600;
        $lock = $this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform ? '' : ' FOR UPDATE';
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->db->beginTransaction();
            try {
                $this->db->insert('infrastructure_samples', ['resource_key' => $resource, 'stream' => $stream, 'bucket' => $minute, 'observed_at' => $observed, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
                $row = $this->db->fetchAssociative('SELECT payload FROM infrastructure_rollups WHERE resource_key = ? AND stream = ? AND bucket = ?'.$lock, [$resource, $stream, $hour]);
                $summary = $row ? InfrastructureAggregation::merge(json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR), InfrastructureAggregation::sample($stream, $observed, $payload)) : $this->hour($resource, $stream, $hour);
                if ($row) {
                    $this->db->update('infrastructure_rollups', ['payload' => json_encode($summary, JSON_THROW_ON_ERROR)], ['resource_key' => $resource, 'stream' => $stream, 'bucket' => $hour]);
                } else {
                    $this->db->insert('infrastructure_rollups', ['resource_key' => $resource, 'stream' => $stream, 'bucket' => $hour, 'payload' => json_encode($summary, JSON_THROW_ON_ERROR)]);
                }
                $this->db->commit();

                return true;
            } catch (UniqueConstraintViolationException|RetryableException $exception) {
                $this->db->rollBack();
                if ($exception instanceof UniqueConstraintViolationException && $this->db->fetchOne('SELECT 1 FROM infrastructure_samples WHERE resource_key = ? AND stream = ? AND bucket = ?', [$resource, $stream, $minute])) {
                    return false;
                }
                if (2 === $attempt) {
                    throw $exception;
                }
            } catch (\Throwable $exception) {
                $this->db->rollBack();
                throw $exception;
            }
        }
        throw new \LogicException('Unable to archive an infrastructure sample.');
    }

    private function hour(string $resource, string $stream, int $hour): array
    {
        $summary = InfrastructureAggregation::empty();
        foreach ($this->raw($resource, $hour, $hour + 3600, $stream) as $row) {
            $summary = InfrastructureAggregation::merge($summary, InfrastructureAggregation::sample($stream, (int) $row['observed_at'], json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR)));
        }

        return $summary;
    }

    private function raw(string $resource, int $from, int $to, ?string $stream = null): \Generator
    {
        $sql = 'SELECT stream, observed_at, payload FROM infrastructure_samples WHERE resource_key = ? AND bucket >= ? AND bucket < ? AND observed_at >= ? AND observed_at < ?';
        $parameters = [$resource, intdiv($from, 60) * 60, (intdiv($to, 60) + 1) * 60, $from, $to];
        if (null !== $stream) {
            $sql .= ' AND stream = ?';
            $parameters[] = $stream;
        }

        return $this->rows($sql.' ORDER BY bucket, stream', $parameters);
    }

    /** PDO MySQL otherwise buffers every raw row before DBAL yields the first one. */
    private function rows(string $sql, array $parameters): \Generator
    {
        $native = $this->db->getNativeConnection();
        $mysql = $native instanceof \PDO && 'mysql' === $native->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $buffered = $mysql ? $native->getAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY) : null;
        $result = null;
        try {
            if ($mysql) {
                $native->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, false);
            }
            $result = $this->db->executeQuery($sql, $parameters);
            yield from $result->iterateAssociative();
        } finally {
            $result?->free();
            if ($mysql) {
                $native->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, $buffered);
            }
        }
    }
}
