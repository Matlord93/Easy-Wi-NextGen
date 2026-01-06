<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PortBlockRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PortBlockRepository::class)]
#[ORM\Table(name: 'port_blocks')]
class PortBlock
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: PortPool::class)]
    #[ORM\JoinColumn(nullable: false)]
    private PortPool $pool;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\OneToOne(targetEntity: Instance::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Instance $instance = null;

    #[ORM\Column]
    private int $startPort;

    #[ORM\Column]
    private int $endPort;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $assignedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(PortPool $pool, User $customer, int $startPort, int $endPort)
    {
        $this->id = bin2hex(random_bytes(16));
        $this->pool = $pool;
        $this->customer = $customer;
        $this->startPort = $startPort;
        $this->endPort = $endPort;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPool(): PortPool
    {
        return $this->pool;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function setCustomer(User $customer): void
    {
        $this->customer = $customer;
        $this->touch();
    }

    public function getInstance(): ?Instance
    {
        return $this->instance;
    }

    public function assignInstance(Instance $instance): void
    {
        $this->instance = $instance;
        $this->assignedAt = new \DateTimeImmutable();
        $this->releasedAt = null;
        $this->touch();
    }

    public function releaseInstance(): void
    {
        $this->instance = null;
        $this->releasedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function getStartPort(): int
    {
        return $this->startPort;
    }

    public function getEndPort(): int
    {
        return $this->endPort;
    }

    /**
     * @return int[]
     */
    public function getPorts(): array
    {
        if ($this->endPort < $this->startPort) {
            return [];
        }
        return range($this->startPort, $this->endPort);
    }

    public function getAssignedAt(): ?\DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function getReleasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
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
