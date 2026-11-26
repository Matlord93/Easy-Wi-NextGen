<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;

/** @extends \App\Repository\EntityRepositoryQueryInterface<Instance> */
interface InstanceRepositoryInterface extends \App\Repository\EntityRepositoryQueryInterface
{
    /**
     * @return Instance[]
     */
    public function findByCustomer(User $customer): array;

    /**
     * @param list<Agent> $nodes
     * @param list<int|string> $excludedIds
     * @return Instance[]
     */
    public function findScanCandidates(array $nodes, \DateTimeImmutable $now, int $limit, array $excludedIds): array;

    /**
     * @return Instance[]
     */
    public function findWatchdogEnabledBatchAfterId(int $afterId, int $limit): array;
}
