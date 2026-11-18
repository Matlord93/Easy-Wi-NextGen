<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use App\Module\Core\Application\SecretsCryptoInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class DbalBackupPlanStore implements WritableBackupPlanStoreInterface, BackupLeaseStoreInterface
{
    /** @var array<string, string> Tokens owned by this worker, never another worker's lease. */
    private array $lockTokens = [];

    public function __construct(private readonly Connection $connection, private readonly SecretsCryptoInterface $crypto, private readonly BackupPlanJsonCodec $codec)
    {
    }

    #[\Override]
    public function save(BackupPlan $plan): void
    {
        $key = hash('sha256', $plan->id());
        $payload = $this->crypto->encrypt($this->codec->encode($plan));
        $this->replacePayload('backup_plans', $key, $payload);
    }

    #[\Override]
    public function delete(string $planId): bool
    {
        return $this->connection->delete('backup_plans', ['record_key' => hash('sha256', $planId)]) > 0;
    }

    #[\Override]
    public function find(string $planId): ?BackupPlan
    {
        $row = $this->connection->fetchAssociative('SELECT payload FROM backup_plans WHERE record_key = ?', [hash('sha256', $planId)]);
        if ($row === false) {
            return null;
        }
        $plan = $this->codec->decode($this->crypto->decrypt(self::payload($row['payload'])));
        if ($plan->id() !== $planId) {
            throw new \RuntimeException('Backup plan identity does not match its storage key.');
        }

        return $plan;
    }

    #[\Override]
    public function all(): iterable
    {
        foreach ($this->connection->iterateAssociative('SELECT record_key, payload FROM backup_plans ORDER BY record_key') as $row) {
            $plan = $this->codec->decode($this->crypto->decrypt(self::payload($row['payload'])));
            if (hash('sha256', $plan->id()) !== $row['record_key']) {
                throw new \RuntimeException('Backup plan identity does not match its storage key.');
            }
            yield $plan;
        }
    }

    #[\Override]
    public function saveRun(BackupRun $run, ?\DateInterval $idempotencyTtl = null): void
    {
        $expires = $run->createdAt()->add($idempotencyTtl ?? new \DateInterval('PT24H'))->getTimestamp();
        $key = self::runKey($run->planId(), $run->runId());
        $payload = $this->crypto->encrypt(json_encode([
            'version' => 1, 'run_id' => $run->runId(), 'plan_id' => $run->planId(), 'status' => $run->status(),
            'archive_path' => $run->archivePath(), 'size_bytes' => $run->sizeBytes(), 'checksum_sha256' => $run->checksumSha256(),
            'created_at' => $run->createdAt()->format('Y-m-d\TH:i:s.uP'), 'restored' => $run->restored(),
        ], JSON_THROW_ON_ERROR));
        $this->replacePayload('backup_plan_runs', $key, $payload, $expires);
    }

    #[\Override]
    public function hasRunForIdempotency(string $planId, string $idempotencyKey): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM backup_plan_runs WHERE record_key = ? AND expires_at > ?', [self::runKey($planId, $idempotencyKey), time()]) !== false;
    }

    #[\Override]
    public function acquireLock(string $scope, \DateTimeImmutable $expiresAt): bool
    {
        $now = time();
        if ($expiresAt->getTimestamp() <= $now) {
            return false;
        }
        $key = hash('sha256', $scope);
        $token = bin2hex(random_bytes(24));
        $data = ['record_key' => $key, 'owner_token' => $token, 'expires_at' => $expiresAt->getTimestamp()];
        try {
            $this->connection->insert('backup_plan_locks', $data);
        } catch (UniqueConstraintViolationException) {
            if ($this->connection->executeStatement('UPDATE backup_plan_locks SET owner_token = ?, expires_at = ? WHERE record_key = ? AND expires_at <= ?', [$token, $expiresAt->getTimestamp(), $key, $now]) !== 1) {
                return false;
            }
        }
        $this->lockTokens[$key] = $token;

        return true;
    }

    #[\Override]
    public function releaseLock(string $scope): void
    {
        $key = hash('sha256', $scope);
        $token = $this->lockTokens[$key] ?? null;
        if ($token !== null) {
            $this->connection->delete('backup_plan_locks', ['record_key' => $key, 'owner_token' => $token]);
            unset($this->lockTokens[$key]);
        }
    }

    #[\Override]
    public function renewLock(string $scope, \DateTimeImmutable $expiresAt): bool
    {
        $key = hash('sha256', $scope);
        $token = $this->lockTokens[$key] ?? null;
        $now = time();
        if ($token === null || $expiresAt->getTimestamp() <= $now) {
            return false;
        }
        $count = $this->connection->executeStatement('UPDATE backup_plan_locks SET expires_at = ? WHERE record_key = ? AND owner_token = ? AND expires_at > ?', [$expiresAt->getTimestamp(), $key, $token, $now]);

        return $count === 1 || $this->connection->fetchOne('SELECT 1 FROM backup_plan_locks WHERE record_key = ? AND owner_token = ? AND expires_at > ?', [$key, $token, $now]) !== false;
    }

    #[\Override]
    public function cleanupExpiredState(\DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('DELETE FROM backup_plan_runs WHERE expires_at <= ?', [$now->getTimestamp()]);
        $this->connection->executeStatement('DELETE FROM backup_plan_locks WHERE expires_at <= ?', [$now->getTimestamp()]);
    }

    private static function runKey(string $planId, string $runId): string
    {
        return hash('sha256', json_encode([$planId, $runId], JSON_THROW_ON_ERROR));
    }

    private static function payload(mixed $payload): string
    {
        if (!is_string($payload)) {
            throw new \RuntimeException('Invalid encrypted backup record.');
        }

        return $payload;
    }

    /** Update first; a unique-key race retries the update on both SQLite and MySQL. */
    private function replacePayload(string $table, string $key, string $payload, ?int $expires = null): void
    {
        $data = ['payload' => $payload];
        if ($expires !== null) {
            $data['expires_at'] = $expires;
        }
        if ($this->connection->update($table, $data, ['record_key' => $key]) > 0) {
            return;
        }
        try {
            $this->connection->insert($table, ['record_key' => $key] + $data);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update($table, $data, ['record_key' => $key]);
        }
    }
}
