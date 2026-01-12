<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DdosPolicyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DdosPolicyRepository::class)]
#[ORM\Table(name: 'ddos_policies')]
#[ORM\UniqueConstraint(name: 'uniq_ddos_policy_node', columns: ['node_id'])]
class DdosPolicy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(name: 'node_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Agent $node;

    #[ORM\Column(type: 'json')]
    private array $ports = [];

    #[ORM\Column(type: 'json')]
    private array $protocols = [];

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $mode = null;

    #[ORM\Column(type: 'boolean')]
    private bool $enabled;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $appliedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param int[] $ports
     * @param string[] $protocols
     */
    public function __construct(
        Agent $node,
        array $ports,
        array $protocols,
        ?string $mode,
        bool $enabled,
        \DateTimeImmutable $appliedAt,
    ) {
        $this->node = $node;
        $this->ports = $ports;
        $this->protocols = $protocols;
        $this->mode = $mode;
        $this->enabled = $enabled;
        $this->appliedAt = $appliedAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): int
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
     * @return string[]
     */
    public function getProtocols(): array
    {
        return $this->protocols;
    }

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getAppliedAt(): \DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param int[] $ports
     * @param string[] $protocols
     */
    public function updatePolicy(
        array $ports,
        array $protocols,
        ?string $mode,
        bool $enabled,
        \DateTimeImmutable $appliedAt,
    ): void {
        $this->ports = $ports;
        $this->protocols = $protocols;
        $this->mode = $mode;
        $this->enabled = $enabled;
        $this->appliedAt = $appliedAt;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
