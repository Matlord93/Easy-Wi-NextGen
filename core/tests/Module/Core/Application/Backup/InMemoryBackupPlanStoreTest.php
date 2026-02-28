<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\BackupPlan;
use App\Module\Core\Application\Backup\BackupRun;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\InMemoryBackupPlanStore;
use App\Module\Core\Application\Backup\RetentionPolicy;
use PHPUnit\Framework\TestCase;

final class InMemoryBackupPlanStoreTest extends TestCase
{
    public function testPlanLockPreventsParallelRunsUntilReleased(): void
    {
        $plan = new BackupPlan('p1', 'web', '/srv/web', new BackupStorageTarget('local', 'l', ['path' => '/tmp']), new RetentionPolicy(1, 1));
        $store = new InMemoryBackupPlanStore([$plan]);

        self::assertTrue($store->acquireLock('plan:p1', new \DateTimeImmutable('+5 minutes')));
        self::assertFalse($store->acquireLock('plan:p1', new \DateTimeImmutable('+5 minutes')));

        $store->releaseLock('plan:p1');
        self::assertTrue($store->acquireLock('plan:p1', new \DateTimeImmutable('+5 minutes')));
    }

    public function testIdempotencyKeyExpiresAfterTtlAndCleanup(): void
    {
        $store = new InMemoryBackupPlanStore();
        $createdAt = new \DateTimeImmutable('-2 hours');
        $run = new BackupRun('idem-1', 'plan-1', 'succeeded', '/tmp/run.tar', 1, 'sum', $createdAt);

        $store->saveRun($run, new \DateInterval('PT30M'));
        $store->cleanupExpiredState(new \DateTimeImmutable());

        self::assertFalse($store->hasRunForIdempotency('plan-1', 'idem-1'));
    }
}
