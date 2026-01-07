<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Event\ResourceEventSource;
use App\Domain\Event\ResourceEventSourceTrait;
use App\Repository\WebspaceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebspaceRepository::class)]
#[ORM\Table(name: 'webspaces')]
class Webspace implements ResourceEventSource
{
    use ResourceEventSourceTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Agent $node;

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(length: 20)]
    private string $phpVersion;

    #[ORM\Column]
    private int $quota;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $customer, Agent $node, string $path, string $phpVersion, int $quota)
    {
        $this->customer = $customer;
        $this->node = $node;
        $this->path = $path;
        $this->phpVersion = $phpVersion;
        $this->quota = $quota;
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

    public function getNode(): Agent
    {
        return $this->node;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getPhpVersion(): string
    {
        return $this->phpVersion;
    }

    public function getQuota(): int
    {
        return $this->quota;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
        $this->touch();
    }

    public function setPhpVersion(string $phpVersion): void
    {
        $this->phpVersion = $phpVersion;
        $this->touch();
    }

    public function setQuota(int $quota): void
    {
        $this->quota = $quota;
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
