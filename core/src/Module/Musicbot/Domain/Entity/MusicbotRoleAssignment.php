<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Enum\MusicbotRoleSubjectType;
use App\Repository\MusicbotRoleAssignmentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotRoleAssignmentRepository::class)]
#[ORM\Table(name: 'musicbot_role_assignments')]
#[ORM\Index(name: 'idx_mra_role', columns: ['role_id'])]
#[ORM\Index(name: 'idx_mra_subject', columns: ['subject_type', 'subject_id'])]
#[ORM\UniqueConstraint(name: 'uniq_mra_role_subject', columns: ['role_id', 'subject_type', 'subject_id'])]
class MusicbotRoleAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MusicbotRole::class, inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotRole $role;

    #[ORM\Column(enumType: MusicbotRoleSubjectType::class, length: 32)]
    private MusicbotRoleSubjectType $subjectType;

    /** Identifier of the subject – user ID, TS UID, API token ID, Discord snowflake, etc. */
    #[ORM\Column(length: 128)]
    private string $subjectId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $grantedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        MusicbotRole $role,
        MusicbotRoleSubjectType $subjectType,
        string $subjectId,
        ?User $grantedBy = null,
    ) {
        $this->role        = $role;
        $this->subjectType = $subjectType;
        $this->subjectId   = $subjectId;
        $this->grantedBy   = $grantedBy;
        $this->createdAt   = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getRole(): MusicbotRole { return $this->role; }
    public function getSubjectType(): MusicbotRoleSubjectType { return $this->subjectType; }
    public function getSubjectId(): string { return $this->subjectId; }
    public function getGrantedBy(): ?User { return $this->grantedBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
