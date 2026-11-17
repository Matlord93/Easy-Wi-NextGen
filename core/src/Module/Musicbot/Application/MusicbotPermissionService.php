<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotPermission;
use App\Module\Musicbot\Domain\Enum\MusicbotRoleChannel;
use App\Module\Musicbot\Domain\Enum\MusicbotRoleSubjectType;
use App\Module\Musicbot\Domain\Exception\MusicbotPermissionDeniedException;

final class MusicbotPermissionService
{
    public function __construct(
        private readonly MusicbotPlanLimitResolver $limitResolver,
        private readonly MusicbotRoleService $roleService,
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

    /**
     * Combined plan-level + instance-level role check.
     *
     * - Admins are always allowed.
     * - The bot owner is checked against the plan-level permission gate.
     * - Non-owners (team members, API token holders, etc.) are checked against
     *   their role assignments on the specific instance.
     */
    public function assertActionAllowed(
        User $actor,
        MusicbotInstance $instance,
        MusicbotPermission $permission,
        MusicbotRoleChannel $channel = MusicbotRoleChannel::Api,
    ): void {
        if ($actor->isAdmin()) {
            return;
        }

        if ($actor->getId() === $instance->getCustomer()->getId()) {
            $this->assertPermission($actor, $permission);

            return;
        }

        $subjectType = match ($channel) {
            MusicbotRoleChannel::Api       => MusicbotRoleSubjectType::ApiToken,
            MusicbotRoleChannel::Panel     => MusicbotRoleSubjectType::PanelUser,
            MusicbotRoleChannel::Teamspeak => MusicbotRoleSubjectType::TeamspeakUid,
            MusicbotRoleChannel::Discord   => MusicbotRoleSubjectType::DiscordUser,
        };

        $this->roleService->assertInstancePermission(
            $instance,
            $subjectType,
            (string) $actor->getId(),
            $permission,
            $channel,
        );
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
