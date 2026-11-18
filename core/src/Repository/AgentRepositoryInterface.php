<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Agent;

/** @extends \App\Repository\EntityRepositoryQueryInterface<Agent> */
interface AgentRepositoryInterface extends \App\Repository\EntityRepositoryQueryInterface
{
    /** @return Agent[] */
    public function findActive(): array;

    /** @return array{total:int,online:int,degraded:int,offline:int} */
    public function countHeartbeatStates(\DateTimeImmutable $onlineSince, \DateTimeImmutable $degradedSince): array;
}
