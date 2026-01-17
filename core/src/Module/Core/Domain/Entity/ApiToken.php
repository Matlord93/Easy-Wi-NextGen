<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_tokens')]
#[ORM\Index(name: 'idx_api_tokens_token_hash', columns: ['token_hash'])]
class ApiToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(name: 'token_prefix', length: 16)]
    private string $tokenPrefix;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\Column(type: 'json')]
    private array $encryptedToken;

    #[ORM\Column(type: 'json')]
    private array $scopes;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rotatedAt = null;

    /**
     * @param string[] $scopes
     * @param array{key_id: string, nonce: string, ciphertext: string} $encryptedToken
     */
    public function __construct(
        User $customer,
        string $name,
        array $scopes,
        string $tokenPrefix,
        string $tokenHash,
        array $encryptedToken,
        ?\DateTimeImmutable $expiresAt = null,
    ) {
        $this->customer = $customer;
        $this->name = $name;
        $this->tokenPrefix = $tokenPrefix;
        $this->tokenHash = $tokenHash;
        $this->encryptedToken = $encryptedToken;
        $this->setScopes($scopes);
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $name = trim($name);
        $this->name = $name !== '' ? $name : $this->name;
        $this->touch();
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

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * @param string[] $scopes
     */
    public function setScopes(array $scopes): void
    {
        $this->scopes = $this->normalizeScopes($scopes);
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

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(\DateTimeImmutable $lastUsedAt): void
    {
        $this->lastUsedAt = $lastUsedAt;
        $this->touch();
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $now = $now ?? new \DateTimeImmutable();
        return $this->expiresAt <= $now;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
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

    public function getRotatedAt(): ?\DateTimeImmutable
    {
        return $this->rotatedAt;
    }

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $encryptedToken
     */
    public function rotate(array $encryptedToken, string $tokenHash, string $tokenPrefix): void
    {
        $this->encryptedToken = $encryptedToken;
        $this->tokenHash = $tokenHash;
        $this->tokenPrefix = $tokenPrefix;
        $this->rotatedAt = new \DateTimeImmutable();
        $this->touch();
    }

    /**
     * @param string[] $scopes
     * @return string[]
     */
    private function normalizeScopes(array $scopes): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(static function ($scope): ?string {
            if (!is_string($scope)) {
                return null;
            }

            $value = trim($scope);
            return $value !== '' ? $value : null;
        }, $scopes))));

        sort($normalized);

        return $normalized;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
