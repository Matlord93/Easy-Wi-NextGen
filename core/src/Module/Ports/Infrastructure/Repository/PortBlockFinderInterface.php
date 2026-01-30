<?php

declare(strict_types=1);

namespace App\Module\Ports\Infrastructure\Repository;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Ports\Domain\Entity\PortBlock;

interface PortBlockFinderInterface
{
    public function findByInstance(Instance $instance): ?PortBlock;
}
