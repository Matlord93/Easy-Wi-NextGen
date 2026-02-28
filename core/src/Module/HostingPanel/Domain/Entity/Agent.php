<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hp_agent')]
class Agent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Node::class, inversedBy: 'agents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Node $node;

    #[ORM\Column(length: 64, unique: true)]
    private string $agentUuid;

    #[ORM\Column(length: 32)]
    private string $version;

    #[ORM\Column(length: 32)]
    private string $os;

    #[ORM\Column(type: 'json')]
    private array $capabilities = [];

    #[ORM\Column(length: 128)]
    private string $tokenHash;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    public function __construct(Node $node, string $agentUuid, string $version, string $os, string $tokenHash)
    {
        $this->node = $node;
        $this->agentUuid = $agentUuid;
        $this->version = $version;
        $this->os = $os;
        $this->tokenHash = $tokenHash;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAgentUuid(): string
    {
        return $this->agentUuid;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function updateHeartbeat(string $version, string $os, array $capabilities): void
    {
        $this->version = $version;
        $this->os = $os;
        $this->capabilities = $capabilities;
        $this->lastSeenAt = new \DateTimeImmutable();
        $this->node->setOnline(true);
    }
}
