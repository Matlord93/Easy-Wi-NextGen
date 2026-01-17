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

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

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
        \DateTimeImmutable $expiresAt,
        ?User $createdBy = null,
    ) {
        $this->name = trim($name);
        $this->tokenPrefix = $tokenPrefix;
        $this->tokenHash = $tokenHash;
        $this->encryptedToken = $encryptedToken;
        $this->expiresAt = $expiresAt;
        $this->createdBy = $createdBy;
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

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): void
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

        return $this->expiresAt <= $now;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
