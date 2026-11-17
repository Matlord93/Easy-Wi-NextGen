<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

interface DatabaseNamingPolicyInterface
{
    /** @return string[] */
    public function validateDatabaseName(string $name): array;

    public function buildCustomerScopedName(int $customerId, string $name): string;

    /** @return string[] */
    public function validateUsername(string $username): array;
}
