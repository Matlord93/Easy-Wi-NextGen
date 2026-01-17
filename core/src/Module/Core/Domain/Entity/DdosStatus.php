<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\DdosStatusRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DdosStatusRepository::class)]
#[ORM\Table(name: 'ddos_statuses')]
#[ORM\UniqueConstraint(name: 'uniq_ddos_status_node', columns: ['node_id'])]
class DdosStatus
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(name: 'node_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Agent $node;

    #[ORM\Column(type: 'boolean')]
    private bool $attackActive;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $packetsPerSecond = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $connectionCount = null;

    #[ORM\Column(type: 'json')]
    private array $ports = [];

    #[ORM\Column(type: 'json')]
    private array $protocols = [];

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $mode = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $reportedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param int[] $ports
     * @param string[] $protocols
     */
    public function __construct(
        Agent $node,
        bool $attackActive,
        ?int $packetsPerSecond,
        ?int $connectionCount,
        array $ports,
        array $protocols,
        ?string $mode,
        \DateTimeImmutable $reportedAt,
    ) {
        $this->node = $node;
        $this->attackActive = $attackActive;
        $this->packetsPerSecond = $packetsPerSecond;
        $this->connectionCount = $connectionCount;
        $this->ports = $ports;
        $this->protocols = $protocols;
        $this->mode = $mode;
        $this->reportedAt = $reportedAt;
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

    public function isAttackActive(): bool
    {
        return $this->attackActive;
    }

    public function getPacketsPerSecond(): ?int
    {
        return $this->packetsPerSecond;
    }

    public function getConnectionCount(): ?int
    {
        return $this->connectionCount;
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

    public function getReportedAt(): \DateTimeImmutable
    {
        return $this->reportedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param int[] $ports
     * @param string[] $protocols
     */
    public function updateStatus(
        bool $attackActive,
        ?int $packetsPerSecond,
        ?int $connectionCount,
        array $ports,
        array $protocols,
        ?string $mode,
        \DateTimeImmutable $reportedAt,
    ): void {
        $this->attackActive = $attackActive;
        $this->packetsPerSecond = $packetsPerSecond;
        $this->connectionCount = $connectionCount;
        $this->ports = $ports;
        $this->protocols = $protocols;
        $this->mode = $mode;
        $this->reportedAt = $reportedAt;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
