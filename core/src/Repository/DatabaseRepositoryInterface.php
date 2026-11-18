<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\User;

/** @extends \App\Repository\EntityRepositoryQueryInterface<Database> */
interface DatabaseRepositoryInterface extends \App\Repository\EntityRepositoryQueryInterface
{
    /** @return Database[] */
    public function findByCustomer(User $customer, int $limit = 100): array;

    public function findOneByCustomerAndName(User $customer, string $engine, string $name): ?Database;

    public function findOneByCustomerAndUsername(User $customer, string $engine, string $username): ?Database;
}
