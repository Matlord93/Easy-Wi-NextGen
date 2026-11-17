<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use Psr\Log\LoggerInterface;

final readonly class BackupRetentionRunner
{
    public function __construct(private BackupLeaseStoreInterface $plans, private BackupArchiveInventory $inventory, private RetentionPruner $pruner, private LoggerInterface $logger, private ?BackupArchiveStorageRegistry $remoteStorage = null)
    {
    }

    /** @return list<BackupRetentionReport> */
    public function runAll(?\DateTimeImmutable $now = null): array
    {
        $reports = [];
        foreach ($this->plans->all() as $plan) {
            if (($plan->options()['retention_enabled'] ?? false) === true) {
                $reports[] = $this->prunePlan($plan->id(), true, $now);
            }
        }

        return $reports;
    }

    public function prunePlan(string $planId, bool $apply = false, ?\DateTimeImmutable $now = null): BackupRetentionReport
    {
        $locked = false;
        $scope = 'plan:'.$planId;
        $candidatePaths = [];
        $deletedPaths = [];
        $blocked = [];
        try {
            $plan = $this->plans->find($planId);
            if (null === $plan) {
                return new BackupRetentionReport($planId, 'failed', 'Backup plan not found.');
            }
            if (($plan->options()['retention_enabled'] ?? false) !== true) {
                return new BackupRetentionReport($planId, 'disabled', 'Automatic retention is not enabled for this plan. No archives were deleted.');
            }
            $root = self::localRoot($plan);
            if ('local' === $plan->target()->type() && null === $root) {
                return new BackupRetentionReport($planId, 'failed', 'Retention requires an existing dedicated local target directory.');
            }
            $configuredTtl = filter_var($plan->options()['lock_ttl_seconds'] ?? 900, FILTER_VALIDATE_INT);
            $ttl = max(30, is_int($configuredTtl) ? $configuredTtl : 900);
            $locked = $this->plans->acquireLock($scope, new \DateTimeImmutable('+'.$ttl.' seconds'));
            if (!$locked) {
                return new BackupRetentionReport($planId, 'busy', 'Backup plan is busy; no retention changes were made.');
            }
            $entries = $this->inventory->forPlan($planId);
            $runs = array_map(static fn (BackupArchiveEntry $entry): BackupRun => $entry->run, $entries);
            $byId = [];
            foreach ($entries as $entry) {
                $byId[$entry->run->runId()] = $entry;
            }
            $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone($plan->timeZone() ?: 'UTC'));
            $candidates = $this->pruner->prune($runs, $plan->retentionPolicy(), $now);
            $candidatePaths = array_map(static fn (BackupRun $run): string => $run->archivePath(), $candidates);
            if ('local' !== $plan->target()->type()) {
                $storage = $this->remoteStorage?->forTarget($plan->target());
                if (null === $storage) {
                    throw new \RuntimeException('Remote archive storage is unavailable.');
                }
                foreach ($candidates as $run) {
                    try {
                        $entry = $byId[$run->runId()];
                        $heartbeat = function () use ($plan, $entry, $scope, $ttl): void {
                            $this->assertRemoteAuthority($plan, $entry, $scope, $ttl);
                        };
                        $heartbeat();
                        $proof = $storage->inspect($plan->target(), $run->archivePath(), $heartbeat);
                        if ($proof->size !== $run->sizeBytes() || !hash_equals($run->checksumSha256(), $proof->sha256)) {
                            throw new \RuntimeException('Archive contents changed after the backup completed.');
                        }
                        $heartbeat();
                        if ($apply) {
                            $storage->delete($plan->target(), $run, $proof, $heartbeat);
                            $deletedPaths[] = $run->archivePath();
                            $this->inventory->forget($run);
                        }
                    } catch (\Throwable $error) {
                        $blocked[] = ['path' => $run->archivePath(), 'reason' => $error->getMessage()];
                        $this->logger->warning('backup.remote_retention_blocked', ['plan_id' => $planId, 'exception' => $error]);
                    }
                }

                return new BackupRetentionReport($planId, [] === $blocked ? ($apply ? 'applied' : 'validated') : 'failed', $apply ? 'Retention processed recorded remote archives.' : 'Validation only; no archives were deleted.', $candidatePaths, $deletedPaths, $blocked);
            }
            if (null === $root) {
                throw new \RuntimeException('Local backup directory is unavailable.');
            }
            $eligible = [];
            $authorize = function (BackupRun $run) use ($byId, $root, $plan, $scope, $ttl, &$blocked): bool {
                $reason = $this->inspect($byId[$run->runId()], $root, $plan, $scope, $ttl);
                if (null !== $reason) {
                    $blocked[] = ['path' => $run->archivePath(), 'reason' => $reason];

                    return false;
                }

                return true;
            };
            if (!$apply) {
                foreach ($candidates as $run) {
                    if ($authorize($run)) {
                        $eligible[] = $run->archivePath();
                    }
                }

                return new BackupRetentionReport($planId, [] === $blocked ? 'validated' : 'failed', 'Validation only; no archives were deleted.', $eligible, [], $blocked);
            }
            $audit = $this->pruner->pruneWithAudit($runs, $plan->retentionPolicy(), $now, $root, $authorize, function (BackupRun $run) use (&$deletedPaths): void {
                // Preserve the completed filesystem action even if subsequent
                // inventory bookkeeping fails and the operation is reported failed.
                $deletedPaths[] = $run->archivePath();
                $this->inventory->forget($run);
            });
            foreach ($audit['skipped'] as $path) {
                if (!str_ends_with($path, ' (validation-failed)')) {
                    $blocked[] = ['path' => $path, 'reason' => 'Archive could not be safely deleted.'];
                }
            }
            $this->logger->info('backup.retention', ['plan_id' => $planId, 'deleted' => count($audit['deleted']), 'blocked' => count($blocked)]);

            return new BackupRetentionReport($planId, [] === $blocked ? 'applied' : 'failed', 'Retention processed recorded local archives.', $candidatePaths, $deletedPaths, $blocked);
        } catch (\Throwable $error) {
            $this->logger->error('backup.retention_failed', ['plan_id' => $planId, 'deleted' => count($deletedPaths), 'exception' => $error]);

            return new BackupRetentionReport($planId, 'failed', 'Retention failed. Check migrations, encryption keys, archive integrity and target permissions.', $candidatePaths, $deletedPaths, $blocked);
        } finally {
            if ($locked) {
                $this->plans->releaseLock($scope);
            }
        }
    }

    private function assertRemoteAuthority(BackupPlan $plan, BackupArchiveEntry $entry, string $scope, int $ttl): void
    {
        if (!$this->plans->renewLock($scope, new \DateTimeImmutable('+'.$ttl.' seconds'))) {
            throw new \RuntimeException('Worker lease expired or was replaced.');
        }
        $identity = BackupTargetIdentity::of($plan->target());
        $current = $this->plans->find($plan->id());
        if (null === $current || ($current->options()['retention_enabled'] ?? false) !== true
            || BackupTargetIdentity::of($current->target()) !== $identity
            || $current->retentionPolicy()->keepCount() !== $plan->retentionPolicy()->keepCount()
            || $current->retentionPolicy()->keepDays() !== $plan->retentionPolicy()->keepDays()) {
            throw new \RuntimeException('Backup plan changed during retention.');
        }
        if ($entry->targetType !== $plan->target()->type() || $entry->targetIdentity !== $identity) {
            throw new \RuntimeException('Archive is not bound to the current remote target and account.');
        }
        if (!$this->inventory->isOnlyReference($entry->run)) {
            throw new \RuntimeException('Archive path is referenced by another recorded run.');
        }
    }

    private function inspect(BackupArchiveEntry $entry, string $root, BackupPlan $plan, string $scope, int $ttl): ?string
    {
        if (!$this->plans->renewLock($scope, new \DateTimeImmutable('+'.$ttl.' seconds'))) {
            return 'Worker lease expired or was replaced.';
        }
        $current = $this->plans->find($plan->id());
        if (null === $current || ($current->options()['retention_enabled'] ?? false) !== true
            || self::localRoot($current) !== $root || $current->retentionPolicy()->keepCount() !== $plan->retentionPolicy()->keepCount()
            || $current->retentionPolicy()->keepDays() !== $plan->retentionPolicy()->keepDays()) {
            return 'Backup plan changed during retention.';
        }
        $run = $entry->run;
        $path = $run->archivePath();
        if ('local' !== $entry->targetType || $entry->localRoot !== $root || dirname($path) !== $root
            || realpath($root) !== $root || is_link($path) || !is_file($path) || realpath($path) !== $path) {
            return 'Archive is missing or no longer belongs to this local target.';
        }
        if (!$this->inventory->isOnlyReference($run)) {
            return 'Archive path is referenced by another recorded run.';
        }
        clearstatcache(true, $path);
        $checksum = @hash_file('sha256', $path);
        if (@filesize($path) !== $run->sizeBytes() || false === $checksum || !hash_equals($run->checksumSha256(), $checksum)) {
            return 'Archive contents changed after the backup completed.';
        }
        if (!$this->plans->renewLock($scope, new \DateTimeImmutable('+'.$ttl.' seconds'))) {
            return 'Worker lease expired while checking the archive.';
        }

        return null;
    }

    private static function localRoot(BackupPlan $plan): ?string
    {
        $configured = $plan->target()->config()['base_path'] ?? $plan->target()->config()['path'] ?? null;
        $root = is_string($configured) && !str_contains($configured, "\0") ? realpath($configured) : false;

        return 'local' === $plan->target()->type() && false !== $root && '/' !== $root && is_dir($root) ? $root : null;
    }
}
