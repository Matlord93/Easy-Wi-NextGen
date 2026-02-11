<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;

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

        if ($this->isCustomerPath($path)) {
            return $actor->getType() === UserType::Customer;
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


    private function isCustomerPath(string $path): bool
    {
        return str_starts_with($path, '/customer')
            || str_starts_with($path, '/api/customer')
            || str_starts_with($path, '/api/v1/customer');
    }

}
