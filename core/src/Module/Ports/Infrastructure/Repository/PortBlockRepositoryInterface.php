<?php

declare(strict_types=1);

namespace App\Module\Ports\Infrastructure\Repository;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Ports\Domain\Entity\PortBlock;

/** @extends \App\Repository\EntityRepositoryQueryInterface<PortBlock> */
interface PortBlockRepositoryInterface extends PortBlockFinderInterface, \App\Repository\EntityRepositoryQueryInterface
{
    /**
     * @return PortBlock[]
     */
    public function findByCustomer(User $customer): array;

    /**
     * @param Instance[] $instances
     *
     * @return PortBlock[]
     */
    public function findByInstances(array $instances): array;

    /**
     * @return PortBlock[]
     */
    public function findByNode(\App\Module\Core\Domain\Entity\Agent $node): array;

    /**
     * @return PortBlock[]
     */
    public function findAssignedBlocks(): array;
}
