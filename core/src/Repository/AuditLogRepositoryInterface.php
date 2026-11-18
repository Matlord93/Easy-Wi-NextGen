<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\AuditLog;

/** @extends \App\Repository\EntityRepositoryQueryInterface<AuditLog> */
interface AuditLogRepositoryInterface extends \App\Repository\EntityRepositoryQueryInterface
{
    public function findLatestHash(): ?string;

    /**
     * @return AuditLog[]
     */
    public function findRecent(int $limit = 30, ?\App\Module\Core\Domain\Entity\User $actor = null): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findRecentSummaries(int $limit = 50): array;

    /** @return array<int, array<string, mixed>> */
    public function searchSummaries(?string $action, ?string $actorEmail, ?\DateTimeImmutable $since, int $limit = 100): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findRecentActivitySummaries(int $limit = 40): array;

    /** @param list<string> $actions */
    public function deleteRoutineActionsOlderThan(array $actions, \DateTimeImmutable $cutoff): int;

    /**
     * @param list<string> $actions
     * @return AuditLog[]
     */
    public function findRecentByActions(array $actions, int $limit = 50): array;
}
