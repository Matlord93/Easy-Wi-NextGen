<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'access_role_assignments')]
#[ORM\UniqueConstraint(name: 'uniq_access_role_user', columns: ['role_id', 'user_id'])]
class AccessRoleAssignment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: AccessRole::class)] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private AccessRole $role,
        #[ORM\ManyToOne(targetEntity: User::class)] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private User $user,
    ) {
    }

    public function getRole(): AccessRole { return $this->role; }
    public function getUser(): User { return $this->user; }
}
