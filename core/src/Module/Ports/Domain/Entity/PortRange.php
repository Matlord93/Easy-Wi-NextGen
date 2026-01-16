<?php

declare(strict_types=1);

namespace App\Module\Ports\Domain\Entity;

use App\Entity\Agent;
use App\Module\Ports\Infrastructure\Repository\PortRangeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PortRangeRepository::class)]
#[ORM\Table(name: 'port_ranges')]
class PortRange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Agent $node;

    #[ORM\Column(length: 120)]
    private string $purpose;

    #[ORM\Column(length: 8)]
    private string $protocol;

    #[ORM\Column]
    private int $startPort;

    #[ORM\Column]
    private int $endPort;

    #[ORM\Column]
    private bool $enabled;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Agent $node, string $purpose, string $protocol, int $startPort, int $endPort, bool $enabled)
    {
        $this->node = $node;
        $this->purpose = $purpose;
        $this->protocol = $protocol;
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

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function setPurpose(string $purpose): void
    {
        $this->purpose = $purpose;
        $this->touch();
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function setProtocol(string $protocol): void
    {
        $this->protocol = $protocol;
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
