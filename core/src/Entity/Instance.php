<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Event\ResourceEventSource;
use App\Domain\Event\ResourceEventSourceTrait;
use App\Enum\InstanceStatus;
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

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $customer,
        Template $template,
        Agent $node,
        int $cpuLimit,
        int $ramLimit,
        int $diskLimit,
        ?string $portBlockId,
        InstanceStatus $status,
    ) {
        $this->customer = $customer;
        $this->template = $template;
        $this->node = $node;
        $this->cpuLimit = $cpuLimit;
        $this->ramLimit = $ramLimit;
        $this->diskLimit = $diskLimit;
        $this->portBlockId = $portBlockId;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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
