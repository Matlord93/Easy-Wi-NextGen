<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\DatabaseNode;

/** @extends \App\Repository\EntityRepositoryQueryInterface<DatabaseNode> */
interface DatabaseNodeRepositoryInterface extends \App\Repository\EntityRepositoryQueryInterface
{
    /**
     * @return DatabaseNode[]
     */
    public function findActiveByEngine(?string $engine = null): array;
}
