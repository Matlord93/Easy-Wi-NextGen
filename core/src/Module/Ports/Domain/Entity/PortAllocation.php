<?php

declare(strict_types=1);

namespace App\Module\Ports\Domain\Entity;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Ports\Infrastructure\Repository\PortAllocationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PortAllocationRepository::class)]
#[ORM\Table(name: 'port_allocations')]
#[ORM\UniqueConstraint(name: 'uniq_node_proto_port', columns: ['node_id', 'proto', 'port'])]
class PortAllocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Instance::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Instance $instance;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Agent $node;

    #[ORM\Column(length: 80)]
    private string $roleKey;

    #[ORM\Column(length: 6)]
    private string $proto;

    #[ORM\Column]
    private int $port;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $poolTag = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $purpose = null;

    #[ORM\Column(length: 40)]
    private string $allocationStrategy;

    #[ORM\Column]
    private bool $required = true;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $derivedFromRoleKey = null;

    #[ORM\Column(nullable: true)]
    private ?int $derivedOffset = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(nullable: true)]
    private ?bool $lastHostFree = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Instance $instance,
        Agent $node,
        string $roleKey,
        string $proto,
        int $port,
        string $allocationStrategy,
        bool $required,
        ?string $poolTag = null,
        ?string $purpose = null,
        ?string $derivedFromRoleKey = null,
        ?int $derivedOffset = null,
    ) {
        $this->instance = $instance;
        $this->node = $node;
        $this->roleKey = $roleKey;
        $this->proto = $proto;
        $this->port = $port;
        $this->allocationStrategy = $allocationStrategy;
        $this->required = $required;
        $this->poolTag = $poolTag;
        $this->purpose = $purpose;
        $this->derivedFromRoleKey = $derivedFromRoleKey;
        $this->derivedOffset = $derivedOffset;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstance(): Instance
    {
        return $this->instance;
    }

    public function getNode(): Agent
    {
        return $this->node;
    }

    public function getRoleKey(): string
    {
        return $this->roleKey;
    }

    public function getProto(): string
    {
        return $this->proto;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getPoolTag(): ?string
    {
        return $this->poolTag;
    }

    public function getPurpose(): ?string
    {
        return $this->purpose;
    }

    public function getAllocationStrategy(): string
    {
        return $this->allocationStrategy;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getDerivedFromRoleKey(): ?string
    {
        return $this->derivedFromRoleKey;
    }

    public function getDerivedOffset(): ?int
    {
        return $this->derivedOffset;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function getLastHostFree(): ?bool
    {
        return $this->lastHostFree;
    }

    public function setLastCheck(?\DateTimeImmutable $checkedAt, ?bool $hostFree): void
    {
        $this->lastCheckedAt = $checkedAt;
        $this->lastHostFree = $hostFree;
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
