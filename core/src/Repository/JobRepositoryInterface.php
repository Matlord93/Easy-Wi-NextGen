<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\JobStatus;

/** @extends \App\Repository\EntityRepositoryQueryInterface<Job> */
interface JobRepositoryInterface extends \App\Repository\EntityRepositoryQueryInterface
{
    /**
     * @return Job[]
     */
    public function findLatest(int $limit = 50): array;

    /** @return list<Job> */
    public function findLatestHistory(int $limit = 50): array;

    /**
     * @return array{jobs: Job[], total: int}
     */
    public function findPaginatedLatest(int $page, int $perPage = 25): array;

    public function countAll(): int;

    public function countByStatus(JobStatus $status): int;

    /**
     * @return Job[]
     */
    public function findQueuedForDispatchForUpdate(int $limit = 20): array;

    /**
     * @return Job[]
     */
    public function findLatestByType(string $type, int $limit = 100): array;

    public function countRunningByAgentAndTypes(string $agentId, array $types): int;

    public function countRunningByAgent(string $agentId): int;

    /**
     * @param array<int, string> $excludedTypes
     */
    public function countRunningByAgentExcludingTypes(string $agentId, array $excludedTypes): int;

    /**
     * @return Job[]
     */
    public function findRunningWithExpiredLock(\DateTimeImmutable $now, int $limit = 200): array;

    public function findActiveByTypeAndPayloadField(string $type, string $field, string $value, int $limit = 40): ?Job;

    /** @return array<array-key,true> */
    public function findActivePayloadValues(string $type, string $field): array;

    public function findLatestByTypeAndInstanceId(string $type, int $instanceId, int $limit = 50): ?Job;

    public function findLatestActiveByTypeInstanceIdAndScheduleId(string $type, int $instanceId, string $scheduleId, int $limit = 100): ?Job;

    /** @param list<string> $types */
    public function findLatestActiveByTypesAndInstanceId(array $types, int $instanceId, int $limitPerType = 40): ?Job;
}
