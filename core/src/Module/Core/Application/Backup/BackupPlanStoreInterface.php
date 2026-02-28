<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

interface BackupPlanStoreInterface
{
    public function find(string $planId): ?BackupPlan;

    /** @return iterable<BackupPlan> */
    public function all(): iterable;

    public function saveRun(BackupRun $run, ?\DateInterval $idempotencyTtl = null): void;

    public function hasRunForIdempotency(string $planId, string $idempotencyKey): bool;

    public function acquireLock(string $scope, \DateTimeImmutable $expiresAt): bool;

    public function releaseLock(string $scope): void;

    public function cleanupExpiredState(\DateTimeImmutable $now): void;
}
