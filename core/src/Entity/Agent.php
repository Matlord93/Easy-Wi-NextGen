<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AgentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgentRepository::class)]
#[ORM\Table(name: 'agents')]
class Agent
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'json')]
    private array $secretPayload;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastHeartbeatAt = null;

    #[ORM\Column(name: 'last_seen_at', nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $lastHeartbeatIp = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $lastHeartbeatVersion = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $lastHeartbeatStats = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(length: 20)]
    private string $status = 'offline';

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $secretPayload
     */
    public function __construct(string $id, array $secretPayload, ?string $name = null)
    {
        $this->id = $id;
        $this->secretPayload = $secretPayload;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @return array{key_id: string, nonce: string, ciphertext: string}
     */
    public function getSecretPayload(): array
    {
        return $this->secretPayload;
    }

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $payload
     */
    public function setSecretPayload(array $payload): void
    {
        $this->secretPayload = $payload;
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

    public function getLastHeartbeatAt(): ?\DateTimeImmutable
    {
        return $this->lastHeartbeatAt;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getLastHeartbeatIp(): ?string
    {
        return $this->lastHeartbeatIp;
    }

    public function getLastHeartbeatVersion(): ?string
    {
        return $this->lastHeartbeatVersion;
    }

    public function getLastHeartbeatStats(): ?array
    {
        return $this->lastHeartbeatStats;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): void
    {
        $this->metadata = $metadata === [] ? null : $metadata;
        $this->touch();
    }

    /**
     * @return string[]
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /**
     * @param string[] $roles
     */
    public function setRoles(array $roles): void
    {
        $normalized = array_values(array_unique(array_filter(array_map(static function ($role): ?string {
            if (!is_string($role)) {
                return null;
            }

            $value = trim($role);
            return $value !== '' ? $value : null;
        }, $roles))));

        $this->roles = $normalized;
        $this->touch();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status !== '' ? $status : 'offline';
        $this->touch();
    }

    /**
     * @param string[] $roles
     */
    public function recordHeartbeat(array $stats, string $version, ?string $ip, array $roles = [], ?array $metadata = null, ?string $status = null): void
    {
        $seenAt = new \DateTimeImmutable();
        $this->lastHeartbeatAt = $seenAt;
        $this->lastSeenAt = $seenAt;
        $this->lastHeartbeatStats = $stats;
        $this->lastHeartbeatVersion = $version !== '' ? $version : null;
        $this->lastHeartbeatIp = $ip;

        if ($roles !== []) {
            $this->setRoles($roles);
        }

        if ($metadata !== null) {
            $this->setMetadata($metadata);
        }

        $this->setStatus($status ?? 'online');
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
