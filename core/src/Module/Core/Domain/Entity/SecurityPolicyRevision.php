<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\SecurityPolicyRevisionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SecurityPolicyRevisionRepository::class)]
#[ORM\Table(name: 'security_policy_revisions')]
#[ORM\Index(columns: ['node_id', 'policy_type'], name: 'idx_security_policy_node_type')]
#[ORM\UniqueConstraint(name: 'uniq_security_policy_version', columns: ['node_id', 'policy_type', 'version'])]
class SecurityPolicyRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(name: 'node_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Agent $node;

    #[ORM\Column(name: 'policy_type', length: 32)]
    private string $policyType;

    #[ORM\Column(type: 'integer')]
    private int $version;

    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $appliedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 64)]
    private string $checksum;

    public function __construct(
        Agent $node,
        string $policyType,
        int $version,
        array $payload,
        string $status,
        ?User $createdBy = null,
        ?\DateTimeImmutable $appliedAt = null,
    ) {
        $this->node = $node;
        $this->policyType = $policyType;
        $this->version = $version;
        $this->payload = $payload;
        $this->status = $status;
        $this->createdBy = $createdBy;
        $this->appliedAt = $appliedAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->checksum = hash('sha256', json_encode($payload));
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getNode(): Agent
    {
        return $this->node;
    }

    public function getPolicyType(): string
    {
        return $this->policyType;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function markApplied(?\DateTimeImmutable $appliedAt = null): void
    {
        $this->status = 'applied';
        $this->appliedAt = $appliedAt ?? new \DateTimeImmutable();
        $this->touch();
    }

    public function markFailed(): void
    {
        $this->status = 'failed';
        $this->touch();
    }

    public function markPreview(): void
    {
        $this->status = 'preview';
        $this->touch();
    }

    public function markQueued(): void
    {
        $this->status = 'queued';
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
