<?php

declare(strict_types=1);

namespace App\Module\Unifi\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

final readonly class UnifiSyncOutbox
{
    private const LEASE_SECONDS = 300;

    public function __construct(private Connection $connection)
    {
    }

    public function enqueue(int $instanceId, ?\DateTimeImmutable $now = null): void
    {
        if ($instanceId < 1) {
            throw new \InvalidArgumentException('A persisted instance ID is required.');
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('UniFi outbox writes must share the ORM transaction.');
        }
        $time = self::time($now);
        $base = 'INSERT INTO unifi_sync_requests (instance_id, revision, requested_at, available_at, attempts) VALUES (?, 1, ?, ?, 0)';
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            $sql = $base.' ON CONFLICT (instance_id) DO UPDATE SET revision = revision + 1, requested_at = excluded.requested_at, available_at = excluded.available_at';
        } elseif ($platform instanceof AbstractMySQLPlatform) {
            $sql = $base.' ON DUPLICATE KEY UPDATE revision = revision + 1, requested_at = VALUES(requested_at), available_at = VALUES(available_at)';
        } else {
            throw new \LogicException('UniFi outbox supports the configured MySQL or SQLite database.');
        }
        $this->connection->executeStatement($sql, [$instanceId, $time, $time]);
    }

    /** @return list<int> */
    public function dueIds(int $limit = 20, ?\DateTimeImmutable $now = null): array
    {
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('UniFi synchronization must run outside an open transaction.');
        }
        $limit = max(1, min(100, $limit));
        $time = self::time($now);
        return array_map(static fn (mixed $id): int => (int) $id, $this->connection->fetchFirstColumn('SELECT instance_id FROM unifi_sync_requests WHERE available_at <= ? AND (claimed_until IS NULL OR claimed_until <= ?) ORDER BY requested_at, instance_id LIMIT '.$limit, [$time, $time]));
    }

    public function claim(int $instanceId, ?\DateTimeImmutable $now = null): ?UnifiSyncClaim
    {
        $now ??= new \DateTimeImmutable();
        $token = bin2hex(random_bytes(24));
        $time = self::time($now);
        $count = $this->connection->executeStatement('UPDATE unifi_sync_requests SET claim_token = ?, claimed_until = ?, attempts = attempts + 1 WHERE instance_id = ? AND available_at <= ? AND (claimed_until IS NULL OR claimed_until <= ?)', [$token, self::time($now->modify('+'.self::LEASE_SECONDS.' seconds')), $instanceId, $time, $time]);
        if ($count !== 1) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT revision, attempts FROM unifi_sync_requests WHERE instance_id = ? AND claim_token = ?', [$instanceId, $token]);
        if ($row === false || !is_numeric($row['revision']) || !is_numeric($row['attempts'])) {
            throw new \RuntimeException('Claimed UniFi request disappeared.');
        }
        return new UnifiSyncClaim($instanceId, (int) $row['revision'], $token, (int) $row['attempts']);
    }

    public function renew(UnifiSyncClaim $claim, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        // Include expiry in the condition: an expired worker must not recover ownership.
        $count = $this->connection->executeStatement('UPDATE unifi_sync_requests SET claimed_until = ? WHERE instance_id = ? AND claim_token = ? AND claimed_until > ?', [self::time($now->modify('+'.self::LEASE_SECONDS.' seconds')), $claim->instanceId, $claim->token, self::time($now)]);
        if ($count !== 1 && $this->connection->fetchOne('SELECT 1 FROM unifi_sync_requests WHERE instance_id = ? AND claim_token = ? AND claimed_until > ?', [$claim->instanceId, $claim->token, self::time($now)]) === false) {
            throw new \RuntimeException('UniFi synchronization lease was lost or expired.');
        }
    }

    public function acknowledge(UnifiSyncClaim $claim): void
    {
        $this->connection->executeStatement('DELETE FROM unifi_sync_requests WHERE instance_id = ? AND revision = ? AND claim_token = ?', [$claim->instanceId, $claim->revision, $claim->token]);
        $this->releaseNewerRevision($claim);
    }

    public function defer(UnifiSyncClaim $claim, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $this->connection->executeStatement('UPDATE unifi_sync_requests SET claim_token = NULL, claimed_until = NULL, attempts = 0, available_at = ? WHERE instance_id = ? AND revision = ? AND claim_token = ?', [self::time($now->modify('+60 seconds')), $claim->instanceId, $claim->revision, $claim->token]);
        $this->releaseNewerRevision($claim);
    }

    public function retry(UnifiSyncClaim $claim, string $error, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $delay = min(3600, 30 * (1 << min(7, max(0, $claim->attempts - 1))));
        $this->connection->executeStatement('UPDATE unifi_sync_requests SET claim_token = NULL, claimed_until = NULL, last_error = ?, available_at = ? WHERE instance_id = ? AND revision = ? AND claim_token = ?', [mb_substr($error, 0, 2000), self::time($now->modify('+'.$delay.' seconds')), $claim->instanceId, $claim->revision, $claim->token]);
        $this->releaseNewerRevision($claim);
    }

    private function releaseNewerRevision(UnifiSyncClaim $claim): void
    {
        $this->connection->executeStatement('UPDATE unifi_sync_requests SET claim_token = NULL, claimed_until = NULL, attempts = 0, last_error = NULL WHERE instance_id = ? AND revision <> ? AND claim_token = ?', [$claim->instanceId, $claim->revision, $claim->token]);
    }

    private static function time(?\DateTimeImmutable $time): string
    {
        return ($time ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
