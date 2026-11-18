<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\User;

/** @extends \App\Repository\EntityRepositoryQueryInterface<Domain> */
interface DomainRepositoryInterface extends \App\Repository\EntityRepositoryQueryInterface
{
    /**
     * @return Domain[]
     */
    public function findByCustomer(User $customer, int $limit = 200): array;
}
