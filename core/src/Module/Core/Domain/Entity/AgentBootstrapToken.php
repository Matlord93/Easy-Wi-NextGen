<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\AgentBootstrapTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgentBootstrapTokenRepository::class)]
#[ORM\Table(name: 'agent_bootstrap_tokens')]
#[ORM\Index(name: 'idx_agent_bootstrap_tokens_token_hash', columns: ['token_hash'])]
class AgentBootstrapToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(name: 'token_prefix', length: 16)]
    private string $tokenPrefix;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\Column(type: 'json')]
    private array $encryptedToken;

    #[ORM\Column(name: 'bound_cidr', length: 64, nullable: true)]
    private ?string $boundCidr = null;

    #[ORM\Column(name: 'bound_node_name', length: 190, nullable: true)]
    private ?string $boundNodeName = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(name: 'invalidated_at', nullable: true)]
    private ?\DateTimeImmutable $invalidatedAt = null;

    #[ORM\Column(name: 'last_used_at', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'attempts_count')]
    private int $attemptsCount = 0;

    #[ORM\Column(name: 'max_attempts')]
    private int $maxAttempts = 5;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $encryptedToken
     */
    public function __construct(
        string $name,
        string $tokenPrefix,
        string $tokenHash,
        array $encryptedToken,
        ?\DateTimeImmutable $expiresAt,
        ?User $createdBy = null,
        int $maxAttempts = 5,
    ) {
        $this->name = trim($name);
        $this->tokenPrefix = $tokenPrefix;
        $this->tokenHash = $tokenHash;
        $this->encryptedToken = $encryptedToken;
        $this->expiresAt = $expiresAt;
        $this->createdBy = $createdBy;
        $this->maxAttempts = $maxAttempts;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $name = trim($name);
        if ($name !== '') {
            $this->name = $name;
            $this->touch();
        }
    }

    public function getTokenPrefix(): string
    {
        return $this->tokenPrefix;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    /**
     * @return array{key_id: string, nonce: string, ciphertext: string}
     */
    public function getEncryptedToken(): array
    {
        return $this->encryptedToken;
    }

    public function getBoundCidr(): ?string
    {
        return $this->boundCidr;
    }

    public function setBoundCidr(?string $boundCidr): void
    {
        $this->boundCidr = $boundCidr !== null && $boundCidr !== '' ? $boundCidr : null;
        $this->touch();
    }

    public function getBoundNodeName(): ?string
    {
        return $this->boundNodeName;
    }

    public function setBoundNodeName(?string $boundNodeName): void
    {
        $this->boundNodeName = $boundNodeName !== null && $boundNodeName !== '' ? $boundNodeName : null;
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

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
        $this->touch();
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function markUsed(): void
    {
        if ($this->usedAt !== null) {
            return;
        }

        $this->usedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function getInvalidatedAt(): ?\DateTimeImmutable
    {
        return $this->invalidatedAt;
    }

    public function isInvalidated(): bool
    {
        return $this->invalidatedAt !== null;
    }

    public function invalidate(?\DateTimeImmutable $now = null): void
    {
        if ($this->invalidatedAt !== null) {
            return;
        }

        $now = $now ?? new \DateTimeImmutable();
        $this->invalidatedAt = $now;
        if ($this->usedAt === null) {
            $this->usedAt = $now;
        }
        $this->touch();
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getAttemptsCount(): int
    {
        return $this->attemptsCount;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $maxAttempts): void
    {
        $this->maxAttempts = max(0, $maxAttempts);
        $this->touch();
    }

    public function recordAttempt(?\DateTimeImmutable $now = null): void
    {
        $now = $now ?? new \DateTimeImmutable();
        $this->attemptsCount++;
        $this->lastUsedAt = $now;
        $this->touch();
    }

    public function canAttempt(?\DateTimeImmutable $now = null): bool
    {
        if ($this->isRevoked() || $this->isInvalidated()) {
            return false;
        }

        if ($this->isExpired($now)) {
            return false;
        }

        if ($this->maxAttempts > 0 && $this->attemptsCount >= $this->maxAttempts) {
            return false;
        }

        return true;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function revoke(): void
    {
        if ($this->revokedAt !== null) {
            return;
        }

        $this->revokedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        $now = $now ?? new \DateTimeImmutable();

        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt <= $now;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
