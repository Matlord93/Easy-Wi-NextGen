<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotRole;
use App\Module\Musicbot\Domain\Entity\MusicbotRoleAssignment;
use App\Module\Musicbot\Domain\Enum\MusicbotPermission;
use App\Module\Musicbot\Domain\Enum\MusicbotRoleChannel;
use App\Module\Musicbot\Domain\Enum\MusicbotRoleSubjectType;
use App\Module\Musicbot\Domain\Exception\MusicbotPermissionDeniedException;
use App\Repository\MusicbotRoleAssignmentRepository;
use App\Repository\MusicbotRoleRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotRoleService
{
    public function __construct(
        private readonly MusicbotRoleRepository $roleRepository,
        private readonly MusicbotRoleAssignmentRepository $assignmentRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    // -------------------------------------------------------------------------
    // Role CRUD
    // -------------------------------------------------------------------------

    /**
     * @param string[] $permissions Array of MusicbotPermission values
     * @param string[] $channels    Array of MusicbotRoleChannel values (empty = all channels)
     */
    public function createRole(
        MusicbotInstance $instance,
        string $name,
        array $permissions = [],
        array $channels = [],
        ?string $description = null,
        bool $isDefault = false,
        int $position = 0,
    ): MusicbotRole {
        $role = new MusicbotRole($instance, $name);
        $role->setPermissions($permissions);
        $role->setChannels($channels);
        $role->setDescription($description);
        $role->setIsDefault($isDefault);
        $role->setPosition($position);

        $this->em->persist($role);
        $this->em->flush();

        return $role;
    }

    /**
     * @param string[] $permissions
     * @param string[] $channels
     */
    public function updateRole(
        MusicbotRole $role,
        string $name,
        array $permissions,
        array $channels,
        ?string $description = null,
        bool $isDefault = false,
        int $position = 0,
    ): void {
        $role->setName($name);
        $role->setPermissions($permissions);
        $role->setChannels($channels);
        $role->setDescription($description);
        $role->setIsDefault($isDefault);
        $role->setPosition($position);

        $this->em->flush();
    }

    public function deleteRole(MusicbotRole $role): void
    {
        $this->em->remove($role);
        $this->em->flush();
    }

    /** @return MusicbotRole[] */
    public function getRolesForInstance(MusicbotInstance $instance): array
    {
        return $this->roleRepository->findByInstance($instance);
    }

    // -------------------------------------------------------------------------
    // Assignment CRUD
    // -------------------------------------------------------------------------

    public function assign(
        MusicbotRole $role,
        MusicbotRoleSubjectType $subjectType,
        string $subjectId,
        ?User $grantedBy = null,
    ): MusicbotRoleAssignment {
        $existing = $this->assignmentRepository->findOneByRoleAndSubject($role, $subjectType, $subjectId);
        if ($existing !== null) {
            return $existing;
        }

        $assignment = new MusicbotRoleAssignment($role, $subjectType, $subjectId, $grantedBy);
        $this->em->persist($assignment);
        $this->em->flush();

        return $assignment;
    }

    public function revoke(MusicbotRoleAssignment $assignment): void
    {
        $this->em->remove($assignment);
        $this->em->flush();
    }

    /** @return MusicbotRoleAssignment[] */
    public function getAssignmentsForRole(MusicbotRole $role): array
    {
        return $this->assignmentRepository->findByRole($role);
    }

    // -------------------------------------------------------------------------
    // Permission checking
    // -------------------------------------------------------------------------

    /**
     * Returns all effective permissions for a subject on a bot instance,
     * filtered to those active on the given channel.
     *
     * @return string[] Unique MusicbotPermission values
     */
    public function getEffectivePermissions(
        MusicbotInstance $instance,
        MusicbotRoleSubjectType $subjectType,
        string $subjectId,
        MusicbotRoleChannel $channel,
    ): array {
        $assignments = $this->assignmentRepository->findBySubjectAndInstance($subjectType, $subjectId, $instance);

        $permissions = [];
        foreach ($assignments as $assignment) {
            $role = $assignment->getRole();
            if (!$role->isActiveOnChannel($channel)) {
                continue;
            }
            foreach ($role->getPermissions() as $perm) {
                $permissions[$perm] = true;
            }
        }

        return array_keys($permissions);
    }

    public function hasInstancePermission(
        MusicbotInstance $instance,
        MusicbotRoleSubjectType $subjectType,
        string $subjectId,
        MusicbotPermission $permission,
        MusicbotRoleChannel $channel,
    ): bool {
        $effective = $this->getEffectivePermissions($instance, $subjectType, $subjectId, $channel);

        return in_array($permission->value, $effective, true);
    }

    /**
     * Throws if the subject does not hold the required permission on the given channel.
     */
    public function assertInstancePermission(
        MusicbotInstance $instance,
        MusicbotRoleSubjectType $subjectType,
        string $subjectId,
        MusicbotPermission $permission,
        MusicbotRoleChannel $channel,
    ): void {
        if (!$this->hasInstancePermission($instance, $subjectType, $subjectId, $permission, $channel)) {
            throw new MusicbotPermissionDeniedException(
                sprintf(
                    'Permission "%s" is not granted for subject "%s/%s" on channel "%s" for bot #%d.',
                    $permission->value,
                    $subjectType->value,
                    $subjectId,
                    $channel->value,
                    (int) $instance->getId(),
                ),
            );
        }
    }

    /**
     * Auto-assign all default roles of an instance to a new subject.
     */
    public function applyDefaultRoles(
        MusicbotInstance $instance,
        MusicbotRoleSubjectType $subjectType,
        string $subjectId,
        ?User $grantedBy = null,
    ): void {
        foreach ($this->roleRepository->findDefaultsForInstance($instance) as $role) {
            $this->assign($role, $subjectType, $subjectId, $grantedBy);
        }
    }
}
