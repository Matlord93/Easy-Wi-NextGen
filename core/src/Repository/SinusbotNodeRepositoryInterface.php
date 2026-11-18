<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Domain\Entity\User;

/** @extends \App\Repository\EntityRepositoryQueryInterface<SinusbotNode> */
interface SinusbotNodeRepositoryInterface extends \App\Repository\EntityRepositoryQueryInterface
{
    /** @return SinusbotNode[] */
    public function findSoloByCustomer(User $customer): array;
}
