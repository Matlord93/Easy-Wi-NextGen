<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\AccessRole;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\Permission;
use App\Module\Core\Domain\Enum\UserType;

final class PermissionChecker
{
    /** @param iterable<AccessRole> $roles */
    public function isGranted(User $user, Permission $permission, int $siteId, iterable $roles = []): bool
    {
        if ($user->getType() === UserType::Superadmin) {
            return true;
        }
        foreach ($roles as $role) {
            if ($role->getSiteId() === $siteId && $role->grants($permission)) {
                return true;
            }
        }

        return match ($user->getType()) {
            UserType::Admin => in_array($permission, [Permission::BillingRead, Permission::NodesRead, Permission::ShopRead, Permission::UsersRead, Permission::AuditRead], true),
            UserType::Reseller => in_array($permission, [Permission::ShopRead, Permission::UsersRead], true),
            UserType::Customer => false,
            default => false,
        };
    }
}
