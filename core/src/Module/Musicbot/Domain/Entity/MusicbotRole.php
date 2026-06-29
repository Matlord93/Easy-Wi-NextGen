<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Musicbot\Domain\Enum\MusicbotPermission;
use App\Module\Musicbot\Domain\Enum\MusicbotRoleChannel;
use App\Repository\MusicbotRoleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotRoleRepository::class)]
#[ORM\Table(name: 'musicbot_roles')]
#[ORM\Index(name: 'idx_musicbot_roles_instance', columns: ['instance_id'])]
class MusicbotRole
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MusicbotInstance::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotInstance $instance;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    /**
     * Array of MusicbotPermission values (strings) this role grants.
     *
     * @var string[]
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $permissions = [];

    /**
     * Array of MusicbotRoleChannel values this role is active on.
     *
     * @var string[]
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $channels = [];

    /** When true this role is automatically assigned to new subjects on that instance. */
    #[ORM\Column]
    private bool $isDefault = false;

    /** Sort order for display purposes. */
    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, MusicbotRoleAssignment> */
    #[ORM\OneToMany(mappedBy: 'role', targetEntity: MusicbotRoleAssignment::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $assignments;

    public function __construct(MusicbotInstance $instance, string $name)
    {
        $this->instance    = $instance;
        $this->name        = $name;
        $this->createdAt   = new \DateTimeImmutable();
        $this->updatedAt   = $this->createdAt;
        $this->assignments = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getInstance(): MusicbotInstance { return $this->instance; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; $this->touch(); }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description; $this->touch(); }

    /** @return string[] */
    public function getPermissions(): array { return $this->permissions; }

    /** @param string[] $permissions */
    public function setPermissions(array $permissions): void
    {
        $valid = array_map(static fn (MusicbotPermission $p): string => $p->value, MusicbotPermission::cases());
        $this->permissions = array_values(array_intersect($permissions, $valid));
        $this->touch();
    }

    public function hasPermission(MusicbotPermission $permission): bool
    {
        return in_array($permission->value, $this->permissions, true);
    }

    /** @return string[] */
    public function getChannels(): array { return $this->channels; }

    /** @param string[] $channels */
    public function setChannels(array $channels): void
    {
        $valid = array_map(static fn (MusicbotRoleChannel $c): string => $c->value, MusicbotRoleChannel::cases());
        $this->channels = array_values(array_intersect($channels, $valid));
        $this->touch();
    }

    public function isActiveOnChannel(MusicbotRoleChannel $channel): bool
    {
        return empty($this->channels) || in_array($channel->value, $this->channels, true);
    }

    public function isDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $isDefault): void { $this->isDefault = $isDefault; $this->touch(); }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): void { $this->position = $position; $this->touch(); }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, MusicbotRoleAssignment> */
    public function getAssignments(): Collection { return $this->assignments; }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
