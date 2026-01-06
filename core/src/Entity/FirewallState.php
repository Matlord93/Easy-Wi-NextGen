<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FirewallStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FirewallStateRepository::class)]
#[ORM\Table(name: 'firewall_states')]
class FirewallState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private Agent $node;

    #[ORM\Column(type: 'json')]
    private array $ports;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Agent $node, array $ports = [])
    {
        $this->node = $node;
        $this->ports = $ports;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNode(): Agent
    {
        return $this->node;
    }

    /**
     * @return int[]
     */
    public function getPorts(): array
    {
        return $this->ports;
    }

    /**
     * @param int[] $ports
     */
    public function setPorts(array $ports): void
    {
        $this->ports = $ports;
        $this->touch();
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
