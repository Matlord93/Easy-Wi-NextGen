<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;

/** @extends \App\Repository\EntityRepositoryQueryInterface<User> */
interface UserRepositoryInterface extends \App\Repository\EntityRepositoryQueryInterface
{
    public function findOneByEmail(string $email): ?User;

    /**
     * @return User[]
     */
    public function findCustomers(): array;

    /**
     * @return User[]
     */
    public function findCustomersForReseller(User $reseller): array;

    /**
     * @return array<int, array{id: int, email: string, type: string, totpEnabled: bool}>
     */
    public function findTwoFactorOverview(): array;
}
