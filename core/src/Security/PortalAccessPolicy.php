<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserType;

final class PortalAccessPolicy
{
    public function isAllowed(User $actor, string $path): bool
    {
        if ($this->isAdminPath($path)) {
            return $actor->isAdmin();
        }

        if ($this->isResellerPath($path)) {
            return $actor->getType() === UserType::Reseller;
        }

        return true;
    }

    private function isAdminPath(string $path): bool
    {
        return str_starts_with($path, '/admin')
            || str_starts_with($path, '/api/admin')
            || str_starts_with($path, '/api/v1/admin');
    }

    private function isResellerPath(string $path): bool
    {
        return str_starts_with($path, '/reseller')
            || str_starts_with($path, '/api/reseller')
            || str_starts_with($path, '/api/v1/reseller');
    }
}
