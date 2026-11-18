<?php

declare(strict_types=1);

namespace App\Module\Ports\Infrastructure\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Ports\Domain\Entity\PortPool;

/** @extends \App\Repository\EntityRepositoryQueryInterface<PortPool> */
interface PortPoolRepositoryInterface extends \App\Repository\EntityRepositoryQueryInterface
{
    /**
     * @return PortPool[]
     */
    public function findEnabledByNode(Agent $node): array;
}
