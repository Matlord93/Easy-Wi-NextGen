<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Event\ResourceEventSource;
use App\Domain\Event\ResourceEventSourceTrait;
use App\Enum\InstanceStatus;
use App\Enum\InstanceUpdatePolicy;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\InstanceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstanceRepository::class)]
#[ORM\Table(name: 'instances')]
class Instance implements ResourceEventSource
{
    use ResourceEventSourceTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: Template::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Template $template;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Agent $node;

    #[ORM\Column]
    private int $cpuLimit;

    #[ORM\Column]
    private int $ramLimit;

    #[ORM\Column]
    private int $diskLimit;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $portBlockId = null;

    #[ORM\Column(enumType: InstanceStatus::class)]
    private InstanceStatus $status;

    #[ORM\Column(enumType: InstanceUpdatePolicy::class)]
    private InstanceUpdatePolicy $updatePolicy;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $lockedBuildId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lockedVersion = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $currentBuildId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $currentVersion = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $previousBuildId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $previousVersion = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUpdateQueuedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, InstanceSchedule>
     */
    #[ORM\OneToMany(mappedBy: 'instance', targetEntity: InstanceSchedule::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $schedules;

    public function __construct(
        User $customer,
        Template $template,
        Agent $node,
        int $cpuLimit,
        int $ramLimit,
        int $diskLimit,
        ?string $portBlockId,
        InstanceStatus $status,
        InstanceUpdatePolicy $updatePolicy = InstanceUpdatePolicy::Manual,
    ) {
        $this->customer = $customer;
        $this->template = $template;
        $this->node = $node;
        $this->cpuLimit = $cpuLimit;
        $this->ramLimit = $ramLimit;
        $this->diskLimit = $diskLimit;
        $this->portBlockId = $portBlockId;
        $this->status = $status;
        $this->updatePolicy = $updatePolicy;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->schedules = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getTemplate(): Template
    {
        return $this->template;
    }

    public function getNode(): Agent
    {
        return $this->node;
    }

    public function getCpuLimit(): int
    {
        return $this->cpuLimit;
    }

    public function setCpuLimit(int $cpuLimit): void
    {
        $this->cpuLimit = $cpuLimit;
        $this->touch();
    }

    public function getRamLimit(): int
    {
        return $this->ramLimit;
    }

    public function setRamLimit(int $ramLimit): void
    {
        $this->ramLimit = $ramLimit;
        $this->touch();
    }

    public function getDiskLimit(): int
    {
        return $this->diskLimit;
    }

    public function setDiskLimit(int $diskLimit): void
    {
        $this->diskLimit = $diskLimit;
        $this->touch();
    }

    public function getPortBlockId(): ?string
    {
        return $this->portBlockId;
    }

    public function setPortBlockId(?string $portBlockId): void
    {
        $this->portBlockId = $portBlockId;
        $this->touch();
    }

    public function getStatus(): InstanceStatus
    {
        return $this->status;
    }

    public function setStatus(InstanceStatus $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function getUpdatePolicy(): InstanceUpdatePolicy
    {
        return $this->updatePolicy;
    }

    public function setUpdatePolicy(InstanceUpdatePolicy $updatePolicy): void
    {
        $this->updatePolicy = $updatePolicy;
        $this->touch();
    }

    public function getLockedBuildId(): ?string
    {
        return $this->lockedBuildId;
    }

    public function setLockedBuildId(?string $lockedBuildId): void
    {
        $this->lockedBuildId = $lockedBuildId;
        $this->touch();
    }

    public function getLockedVersion(): ?string
    {
        return $this->lockedVersion;
    }

    public function setLockedVersion(?string $lockedVersion): void
    {
        $this->lockedVersion = $lockedVersion;
        $this->touch();
    }

    public function getCurrentBuildId(): ?string
    {
        return $this->currentBuildId;
    }

    public function setCurrentBuildId(?string $currentBuildId): void
    {
        $this->currentBuildId = $currentBuildId;
        $this->touch();
    }

    public function getCurrentVersion(): ?string
    {
        return $this->currentVersion;
    }

    public function setCurrentVersion(?string $currentVersion): void
    {
        $this->currentVersion = $currentVersion;
        $this->touch();
    }

    public function getPreviousBuildId(): ?string
    {
        return $this->previousBuildId;
    }

    public function setPreviousBuildId(?string $previousBuildId): void
    {
        $this->previousBuildId = $previousBuildId;
        $this->touch();
    }

    public function getPreviousVersion(): ?string
    {
        return $this->previousVersion;
    }

    public function setPreviousVersion(?string $previousVersion): void
    {
        $this->previousVersion = $previousVersion;
        $this->touch();
    }

    public function getLastUpdateQueuedAt(): ?\DateTimeImmutable
    {
        return $this->lastUpdateQueuedAt;
    }

    public function setLastUpdateQueuedAt(?\DateTimeImmutable $lastUpdateQueuedAt): void
    {
        $this->lastUpdateQueuedAt = $lastUpdateQueuedAt;
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

    /**
     * @return Collection<int, InstanceSchedule>
     */
    public function getSchedules(): Collection
    {
        return $this->schedules;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
