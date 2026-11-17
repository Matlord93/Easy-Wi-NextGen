<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RunBackupPlanMessage;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\Backup\BackupAgent;
use App\Module\Core\Application\Backup\BackupLeaseStoreInterface;
use App\Module\Core\Application\Backup\BackupOperationHeartbeat;
use App\Module\Core\Application\Backup\BackupPlanStoreInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunBackupPlanMessageHandler
{
    public function __construct(
        private readonly BackupPlanStoreInterface $planStore,
        private readonly BackupAgent $backupAgent,
        private readonly AuditLogger $auditLogger,
        private readonly ?BackupOperationHeartbeat $heartbeat = null,
        private readonly ?BackupLeaseStoreInterface $leases = null,
    ) {
    }

    public function __invoke(RunBackupPlanMessage $message): void
    {
        $this->planStore->cleanupExpiredState(new \DateTimeImmutable());

        $plan = $this->planStore->find($message->planId());
        if ($plan === null) {
            return;
        }

        $lockTtlSeconds = max(30, self::ttl($plan->options()['lock_ttl_seconds'] ?? null, 900));
        $lockExpiresAt = new \DateTimeImmutable(sprintf('+%d seconds', $lockTtlSeconds));

        $planLockScope = 'plan:'.$plan->id();
        if (!$this->planStore->acquireLock($planLockScope, $lockExpiresAt)) {
            return;
        }

        $targetLockScope = null;
        $targetLockEnabled = (bool) ($plan->options()['lock_target'] ?? false);
        $previous = null;
        $heartbeatEntered = false;
        try {
            if ($targetLockEnabled) {
                $scope = 'target:'.$plan->target()->type().':'.hash('sha256', json_encode($plan->target()->config(), JSON_THROW_ON_ERROR));
                if (!$this->planStore->acquireLock($scope, $lockExpiresAt)) {
                    return;
                }
                $targetLockScope = $scope;
            }
            $previous = $this->heartbeat?->enter(function () use ($planLockScope, $targetLockScope, $lockTtlSeconds): void {
                if ($this->leases === null) {
                    return;
                }
                $expires = new \DateTimeImmutable(sprintf('+%d seconds', $lockTtlSeconds));
                foreach (array_filter([$planLockScope, $targetLockScope]) as $scope) {
                    if (!$this->leases->renewLock($scope, $expires)) {
                        throw new \RuntimeException('Backup lease expired or changed ownership; operation stopped.');
                    }
                }
            });
            $heartbeatEntered = true;
            $this->heartbeat?->pulse(true);
            $idempotencyKey = $message->idempotencyKey();
            if (is_string($idempotencyKey) && $idempotencyKey !== '' && $this->planStore->hasRunForIdempotency($plan->id(), $idempotencyKey)) {
                return;
            }

            $run = $this->backupAgent->run($plan, $idempotencyKey);
            $this->heartbeat?->pulse(true);
            $ttlSeconds = max(60, self::ttl($plan->options()['idempotency_ttl_seconds'] ?? null, 86400));
            $this->planStore->saveRun($run, new \DateInterval(sprintf('PT%dS', $ttlSeconds)));

            $this->auditLogger->log(null, 'backup.plan.run', [
                'plan_id' => $plan->id(),
                'run_id' => $run->runId(),
                'manual' => $message->manual(),
                'archive_path' => $run->archivePath(),
                'checksum_sha256' => $run->checksumSha256(),
                'target_lock' => $targetLockEnabled,
            ]);
        } finally {
            if ($heartbeatEntered) {
                $this->heartbeat?->leave($previous);
            }
            try {
                if ($targetLockScope !== null) {
                    $this->planStore->releaseLock($targetLockScope);
                }
            } finally {
                $this->planStore->releaseLock($planLockScope);
            }
        }
    }

    private static function ttl(mixed $value, int $default): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($validated) ? $validated : $default;
    }

}
