<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RunBackupPlanMessage;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\Backup\BackupAgent;
use App\Module\Core\Application\Backup\BackupPlanStoreInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunBackupPlanMessageHandler
{
    public function __construct(
        private readonly BackupPlanStoreInterface $planStore,
        private readonly BackupAgent $backupAgent,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(RunBackupPlanMessage $message): void
    {
        $this->planStore->cleanupExpiredState(new \DateTimeImmutable());

        $plan = $this->planStore->find($message->planId());
        if ($plan === null) {
            return;
        }

        $lockTtlSeconds = max(30, (int) ($plan->options()['lock_ttl_seconds'] ?? 900));
        $lockExpiresAt = new \DateTimeImmutable(sprintf('+%d seconds', $lockTtlSeconds));

        $planLockScope = 'plan:'.$plan->id();
        if (!$this->planStore->acquireLock($planLockScope, $lockExpiresAt)) {
            return;
        }

        $targetLockScope = null;
        $targetLockEnabled = (bool) ($plan->options()['lock_target'] ?? false);
        if ($targetLockEnabled) {
            $targetLockScope = 'target:'.$plan->target()->type().':'.sha1(json_encode($plan->target()->config(), JSON_THROW_ON_ERROR));
            if (!$this->planStore->acquireLock($targetLockScope, $lockExpiresAt)) {
                $this->planStore->releaseLock($planLockScope);
                return;
            }
        }

        try {
            $idempotencyKey = $message->idempotencyKey();
            if (is_string($idempotencyKey) && $idempotencyKey !== '' && $this->planStore->hasRunForIdempotency($plan->id(), $idempotencyKey)) {
                return;
            }

            $run = $this->backupAgent->run($plan, $idempotencyKey);
            $ttlSeconds = max(60, (int) ($plan->options()['idempotency_ttl_seconds'] ?? 86400));
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
            if ($targetLockScope !== null) {
                $this->planStore->releaseLock($targetLockScope);
            }
            $this->planStore->releaseLock($planLockScope);
        }
    }
}
