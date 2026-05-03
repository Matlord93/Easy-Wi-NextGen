<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\TeamMemberRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TeamMemberRepository::class)]
#[ORM\Table(name: 'team_members')]
#[ORM\Index(name: 'idx_team_members_site_sort', columns: ['site_id', 'sort_order'])]
class TeamMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\Column(length: 140)]
    private string $name;

    #[ORM\Column(length: 140)]
    private string $roleTitle;

    #[ORM\Column(length: 140, nullable: true)]
    private ?string $teamName = null;

    #[ORM\Column(type: 'text')]
    private string $bio = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarPath = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $socialsJson = null;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Site $site, string $name, string $roleTitle)
    {
        $this->site = $site;
        $this->name = trim($name);
        $this->roleTitle = trim($roleTitle);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getSite(): Site
    {
        return $this->site;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function setName(string $name): void
    {
        $this->name = trim($name);
        $this->touch();
    }
    public function getRoleTitle(): string
    {
        return $this->roleTitle;
    }
    public function setRoleTitle(string $roleTitle): void
    {
        $this->roleTitle = trim($roleTitle);
        $this->touch();
    }
    public function getTeamName(): ?string
    {
        return $this->teamName;
    }
    public function setTeamName(?string $teamName): void
    {
        $value = $teamName === null ? null : trim($teamName);
        $this->teamName = $value !== '' ? $value : null;
        $this->touch();
    }
    public function getBio(): string
    {
        return $this->bio;
    }
    public function setBio(string $bio): void
    {
        $this->bio = $bio;
        $this->touch();
    }
    public function getAvatarPath(): ?string
    {
        return $this->avatarPath;
    }
    public function setAvatarPath(?string $avatarPath): void
    {
        $this->avatarPath = $avatarPath === null ? null : trim($avatarPath);
        $this->touch();
    }
    public function getSocialsJson(): ?array
    {
        return $this->socialsJson;
    }
    public function setSocialsJson(?array $socialsJson): void
    {
        $this->socialsJson = $socialsJson;
        $this->touch();
    }
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
        $this->touch();
    }
    public function isActive(): bool
    {
        return $this->isActive;
    }
    public function setActive(bool $isActive): void
    {
        $this->isActive = $isActive;
        $this->touch();
    }
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
