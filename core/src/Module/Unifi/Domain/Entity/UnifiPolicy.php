<?php

declare(strict_types=1);

namespace App\Module\Unifi\Domain\Entity;

use App\Module\Unifi\Infrastructure\Repository\UnifiPolicyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UnifiPolicyRepository::class)]
#[ORM\Table(name: 'unifi_policy')]
class UnifiPolicy
{
    public const MODE_AUTO = 'auto';
    public const MODE_MANUAL = 'manual';
    public const MODE_HYBRID = 'hybrid';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $mode = self::MODE_AUTO;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allowedPorts = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allowedRanges = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allowedProtocols = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allowedTags = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
        $this->touch();
    }

    /**
     * @return int[]
     */
    public function getAllowedPorts(): array
    {
        return is_array($this->allowedPorts) ? $this->allowedPorts : [];
    }

    /**
     * @param int[] $allowedPorts
     */
    public function setAllowedPorts(array $allowedPorts): void
    {
        $this->allowedPorts = $allowedPorts === [] ? null : $allowedPorts;
        $this->touch();
    }

    /**
     * @return array<int, array{start: int, end: int}>
     */
    public function getAllowedRanges(): array
    {
        return is_array($this->allowedRanges) ? $this->allowedRanges : [];
    }

    /**
     * @param array<int, array{start: int, end: int}> $allowedRanges
     */
    public function setAllowedRanges(array $allowedRanges): void
    {
        $this->allowedRanges = $allowedRanges === [] ? null : $allowedRanges;
        $this->touch();
    }

    /**
     * @return string[]
     */
    public function getAllowedProtocols(): array
    {
        return is_array($this->allowedProtocols) ? $this->allowedProtocols : [];
    }

    /**
     * @param string[] $allowedProtocols
     */
    public function setAllowedProtocols(array $allowedProtocols): void
    {
        $this->allowedProtocols = $allowedProtocols === [] ? null : $allowedProtocols;
        $this->touch();
    }

    /**
     * @return string[]
     */
    public function getAllowedTags(): array
    {
        return is_array($this->allowedTags) ? $this->allowedTags : [];
    }

    /**
     * @param string[] $allowedTags
     */
    public function setAllowedTags(array $allowedTags): void
    {
        $this->allowedTags = $allowedTags === [] ? null : $allowedTags;
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
