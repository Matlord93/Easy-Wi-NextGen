<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AgentRegistrationTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgentRegistrationTokenRepository::class)]
#[ORM\Table(name: 'agent_registration_tokens')]
#[ORM\Index(name: 'idx_agent_registration_tokens_token_hash', columns: ['token_hash'])]
class AgentRegistrationToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AgentBootstrapToken::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AgentBootstrapToken $bootstrapToken = null;

    #[ORM\Column(name: 'agent_id', length: 64)]
    private string $agentId;

    #[ORM\Column(name: 'token_prefix', length: 16)]
    private string $tokenPrefix;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\Column(type: 'json')]
    private array $encryptedToken;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $encryptedToken
     */
    public function __construct(
        string $agentId,
        string $tokenPrefix,
        string $tokenHash,
        array $encryptedToken,
        \DateTimeImmutable $expiresAt,
        ?AgentBootstrapToken $bootstrapToken = null,
    ) {
        $this->agentId = $agentId;
        $this->tokenPrefix = $tokenPrefix;
        $this->tokenHash = $tokenHash;
        $this->encryptedToken = $encryptedToken;
        $this->expiresAt = $expiresAt;
        $this->bootstrapToken = $bootstrapToken;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBootstrapToken(): ?AgentBootstrapToken
    {
        return $this->bootstrapToken;
    }

    public function getAgentId(): string
    {
        return $this->agentId;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
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
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        $now = $now ?? new \DateTimeImmutable();

        return $this->expiresAt <= $now;
    }
}
