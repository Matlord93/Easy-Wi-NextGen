<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use App\Module\Core\Application\SecretsCrypto;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class BackupArchiveInventory
{
    public function __construct(private Connection $connection, private SecretsCrypto $crypto)
    {
    }

    public function assertReady(): void
    {
        $this->connection->executeQuery('SELECT record_key FROM backup_archive_inventory WHERE 1 = 0')->free();
    }

    public function record(BackupPlan $plan, BackupRun $run): void
    {
        if ($run->planId() !== $plan->id() || 'succeeded' !== $run->status()) {
            throw new \InvalidArgumentException('Only completed archives belonging to the plan can be recorded.');
        }
        $root = null;
        if ('local' === $plan->target()->type()) {
            $configured = $plan->target()->config()['base_path'] ?? $plan->target()->config()['path'] ?? null;
            $resolved = is_string($configured) ? realpath($configured) : false;
            if (false === $resolved || dirname($run->archivePath()) !== $resolved || is_link($run->archivePath()) || !is_file($run->archivePath())) {
                throw new \RuntimeException('Completed local archive does not belong to its configured target directory.');
            }
            $root = $resolved;
        }
        $key = self::key($run);
        $data = [
            'plan_key' => hash('sha256', $plan->id()),
            'archive_key' => hash('sha256', $run->archivePath()),
            'payload' => $this->crypto->encrypt(json_encode([
                'version' => 1, 'plan_id' => $run->planId(), 'run_id' => $run->runId(),
                'archive_path' => $run->archivePath(), 'size_bytes' => $run->sizeBytes(),
                'checksum_sha256' => $run->checksumSha256(), 'created_at' => $run->createdAt()->format('Y-m-d\TH:i:s.uP'),
                'target_type' => $plan->target()->type(), 'local_root' => $root,
                'target_identity' => BackupTargetIdentity::of($plan->target()),
            ], JSON_THROW_ON_ERROR)),
        ];
        if ($this->connection->update('backup_archive_inventory', $data, ['record_key' => $key]) > 0) {
            return;
        }
        try {
            $this->connection->insert('backup_archive_inventory', ['record_key' => $key] + $data);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update('backup_archive_inventory', $data, ['record_key' => $key]);
        }
    }

    /** @return list<BackupArchiveEntry> */
    public function forPlan(string $planId): array
    {
        $entries = [];
        foreach ($this->connection->iterateAssociative('SELECT record_key, archive_key, payload FROM backup_archive_inventory WHERE plan_key = ? ORDER BY record_key', [hash('sha256', $planId)]) as $row) {
            if (!is_string($row['payload'])) {
                throw new \RuntimeException('Invalid encrypted archive inventory record.');
            }
            $data = json_decode($this->crypto->decrypt($row['payload']), true, 32, JSON_THROW_ON_ERROR);
            $size = is_array($data) ? ($data['size_bytes'] ?? null) : null;
            if (!is_array($data) || ($data['version'] ?? null) !== 1 || ($data['plan_id'] ?? null) !== $planId
                || !is_int($size) || $size < 0
                || !array_key_exists('local_root', $data) || (null !== $data['local_root'] && !is_string($data['local_root']))) {
                throw new \RuntimeException('Invalid archive inventory schema or plan identity.');
            }
            $run = new BackupRun(self::text($data['run_id'] ?? null), $planId, 'succeeded', self::text($data['archive_path'] ?? null), $size, self::text($data['checksum_sha256'] ?? null), new \DateTimeImmutable(self::text($data['created_at'] ?? null)));
            if (self::key($run) !== $row['record_key'] || hash('sha256', $run->archivePath()) !== $row['archive_key']) {
                throw new \RuntimeException('Archive inventory identity does not match its storage keys.');
            }
            $identity = isset($data['target_identity']) ? self::text($data['target_identity']) : null;
            $entries[] = new BackupArchiveEntry($run, self::text($data['target_type'] ?? null), $data['local_root'], $identity);
        }

        return $entries;
    }

    public function isOnlyReference(BackupRun $run): bool
    {
        return false === $this->connection->fetchOne('SELECT 1 FROM backup_archive_inventory WHERE archive_key = ? AND record_key <> ? LIMIT 1', [hash('sha256', $run->archivePath()), self::key($run)]);
    }

    public function forget(BackupRun $run): void
    {
        $this->connection->delete('backup_archive_inventory', ['record_key' => self::key($run), 'archive_key' => hash('sha256', $run->archivePath())]);
    }

    private static function key(BackupRun $run): string
    {
        return hash('sha256', json_encode([$run->planId(), $run->runId()], JSON_THROW_ON_ERROR));
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value) || '' === $value || str_contains($value, "\0")) {
            throw new \RuntimeException('Invalid archive inventory field.');
        }

        return $value;
    }
}
