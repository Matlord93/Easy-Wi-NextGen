<?php

declare(strict_types=1);

namespace App\Module\Ports\Domain\Entity;

use App\Module\Core\Domain\Event\ResourceEventSource;
use App\Module\Core\Domain\Event\ResourceEventSourceTrait;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Ports\Infrastructure\Repository\PortPoolRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PortPoolRepository::class)]
#[ORM\Table(name: 'port_pools')]
class PortPool implements ResourceEventSource
{
    use ResourceEventSourceTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Agent $node;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 120)]
    private string $tag;

    #[ORM\Column]
    private int $startPort;

    #[ORM\Column]
    private int $endPort;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Agent $node, string $name, string $tag, int $startPort, int $endPort, bool $enabled = true)
    {
        $this->node = $node;
        $this->name = $name;
        $this->tag = $tag;
        $this->startPort = $startPort;
        $this->endPort = $endPort;
        $this->enabled = $enabled;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNode(): Agent
    {
        return $this->node;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function setTag(string $tag): void
    {
        $this->tag = $tag;
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

    public function setRange(int $startPort, int $endPort): void
    {
        $this->startPort = $startPort;
        $this->endPort = $endPort;
        $this->touch();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
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
