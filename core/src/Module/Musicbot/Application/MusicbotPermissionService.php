<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Enum\MusicbotPermission;
use App\Module\Musicbot\Domain\Exception\MusicbotPermissionDeniedException;

final class MusicbotPermissionService
{
    public function __construct(
        private readonly MusicbotPlanLimitResolver $limitResolver,
    ) {
    }

    public function hasPermission(User $user, MusicbotPermission $permission): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $limits = $this->limitResolver->resolve($user);

        return in_array($permission->value, $limits->grantedPermissions, true);
    }

    public function assertPermission(User $user, MusicbotPermission $permission): void
    {
        if (!$this->hasPermission($user, $permission)) {
            throw new MusicbotPermissionDeniedException(
                sprintf('Permission "%s" is not granted for your account.', $permission->value),
            );
        }
    }

    /** @return string[] */
    public function getGrantedPermissions(User $user): array
    {
        if ($user->isAdmin()) {
            return array_map(static fn (MusicbotPermission $p): string => $p->value, MusicbotPermission::cases());
        }

        return $this->limitResolver->resolve($user)->grantedPermissions;
    }
}
