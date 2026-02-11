<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ForumMemberBanRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ForumMemberBanRepository::class)]
#[ORM\Table(name: 'forum_member_bans')]
#[ORM\UniqueConstraint(name: 'uniq_forum_member_bans_user', columns: ['user_id'])]
class ForumMemberBan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $bannedUntil = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getUser(): User
    {
        return $this->user;
    }
    public function getBannedUntil(): ?\DateTimeImmutable
    {
        return $this->bannedUntil;
    }
    public function setBannedUntil(?\DateTimeImmutable $bannedUntil): void
    {
        $this->bannedUntil = $bannedUntil;
    }
    public function getReason(): ?string
    {
        return $this->reason;
    }
    public function setReason(?string $reason): void
    {
        $this->reason = $reason !== null ? trim($reason) : null;
    }

    public function isActive(): bool
    {
        return $this->bannedUntil === null || $this->bannedUntil > new \DateTimeImmutable();
    }
}
