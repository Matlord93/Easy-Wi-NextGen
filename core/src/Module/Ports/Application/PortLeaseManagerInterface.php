<?php

declare(strict_types=1);

namespace App\Module\Ports\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Ports\Domain\Entity\PortBlock;
use App\Module\Ports\Domain\Entity\PortPool;

interface PortLeaseManagerInterface
{
    /**
     * @return PortBlock[]
     */
    public function allocateBlocksInRange(PortPool $pool, User $customer, int $startPort, int $endPort, int $size): array;

    public function allocateBlock(PortPool $pool, User $customer, int $size): PortBlock;

    public function assertAssignable(PortBlock $block, \App\Module\Core\Domain\Entity\Template $template): void;
}
