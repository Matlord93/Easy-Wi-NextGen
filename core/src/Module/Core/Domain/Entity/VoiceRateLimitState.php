<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\VoiceRateLimitStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VoiceRateLimitStateRepository::class)]
#[ORM\Table(name: 'voice_rate_limit_states')]
class VoiceRateLimitState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VoiceNode::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private VoiceNode $node;

    #[ORM\Column(length: 20)]
    private string $providerType;

    #[ORM\Column(type: 'float')]
    private float $tokens = 5.0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $consecutiveFailures = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $circuitOpenUntil = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(VoiceNode $node, string $providerType)
    {
        $this->node = $node;
        $this->providerType = $providerType;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getNode(): VoiceNode
    {
        return $this->node;
    }
    public function getProviderType(): string
    {
        return $this->providerType;
    }
    public function getTokens(): float
    {
        return $this->tokens;
    }
    public function setTokens(float $tokens): void
    {
        $this->tokens = $tokens;
        $this->touch();
    }
    public function getLockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }
    public function setLockedUntil(?\DateTimeImmutable $lockedUntil): void
    {
        $this->lockedUntil = $lockedUntil;
        $this->touch();
    }
    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }
    public function setConsecutiveFailures(int $consecutiveFailures): void
    {
        $this->consecutiveFailures = max(0, $consecutiveFailures);
        $this->touch();
    }
    public function getCircuitOpenUntil(): ?\DateTimeImmutable
    {
        return $this->circuitOpenUntil;
    }
    public function setCircuitOpenUntil(?\DateTimeImmutable $circuitOpenUntil): void
    {
        $this->circuitOpenUntil = $circuitOpenUntil;
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
