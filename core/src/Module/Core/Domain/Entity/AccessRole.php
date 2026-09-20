<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Enum\Permission;
use App\Repository\AccessRoleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccessRoleRepository::class)]
#[ORM\Table(name: 'access_roles')]
#[ORM\UniqueConstraint(name: 'uniq_access_role_site_name', columns: ['site_id', 'name'])]
class AccessRole
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $permissions;

    /** @param list<Permission> $permissions */
    public function __construct(
        #[ORM\Column] private int $siteId,
        #[ORM\Column(length: 100)] private string $name,
        array $permissions = [],
    ) {
        $this->setPermissions($permissions);
    }

    /** @param list<Permission> $permissions */
    public function setPermissions(array $permissions): void
    {
        $this->permissions = array_values(array_unique(array_map(static fn (Permission $permission): string => $permission->value, $permissions)));
    }

    /** @return list<Permission> */
    public function getPermissions(): array
    {
        return array_map(Permission::from(...), $this->permissions);
    }

    public function grants(Permission $permission): bool { return in_array($permission->value, $this->permissions, true); }
    public function getId(): ?int { return $this->id; }
    public function getSiteId(): int { return $this->siteId; }
    public function getName(): string { return $this->name; }
}
