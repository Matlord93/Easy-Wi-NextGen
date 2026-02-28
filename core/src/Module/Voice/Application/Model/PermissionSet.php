<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Model;

final readonly class PermissionSet
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(private array $permissions)
    {
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function has(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
