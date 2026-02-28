<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

final class InMemoryBackupPlanStore implements BackupPlanStoreInterface
{
    /** @var array<string, BackupPlan> */
    private array $plans = [];

    /** @var array<string, BackupRun> */
    private array $runs = [];

    /** @var array<string, \DateTimeImmutable> */
    private array $idempotencyExpires = [];

    /** @var array<string, \DateTimeImmutable> */
    private array $locks = [];

    /** @param iterable<BackupPlan> $plans */
    public function __construct(iterable $plans = [])
    {
        foreach ($plans as $plan) {
            $this->plans[$plan->id()] = $plan;
        }
    }

    public function find(string $planId): ?BackupPlan
    {
        return $this->plans[$planId] ?? null;
    }

    public function all(): iterable
    {
        return array_values($this->plans);
    }

    public function saveRun(BackupRun $run, ?\DateInterval $idempotencyTtl = null): void
    {
        $key = $run->planId().':'.$run->runId();
        $this->runs[$key] = $run;
        $ttl = $idempotencyTtl ?? new \DateInterval('PT24H');
        $this->idempotencyExpires[$key] = $run->createdAt()->add($ttl);
    }

    public function hasRunForIdempotency(string $planId, string $idempotencyKey): bool
    {
        $this->cleanupExpiredState(new \DateTimeImmutable());

        return array_key_exists($planId.':'.$idempotencyKey, $this->runs);
    }

    public function acquireLock(string $scope, \DateTimeImmutable $expiresAt): bool
    {
        $this->cleanupExpiredState(new \DateTimeImmutable());

        if (isset($this->locks[$scope])) {
            return false;
        }

        $this->locks[$scope] = $expiresAt;

        return true;
    }

    public function releaseLock(string $scope): void
    {
        unset($this->locks[$scope]);
    }

    public function cleanupExpiredState(\DateTimeImmutable $now): void
    {
        foreach ($this->idempotencyExpires as $key => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->idempotencyExpires[$key], $this->runs[$key]);
            }
        }

        foreach ($this->locks as $scope => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->locks[$scope]);
            }
        }
    }
}
