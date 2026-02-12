<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\DatabaseNodeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DatabaseNodeRepository::class)]
#[ORM\Table(name: 'database_nodes')]
class DatabaseNode
{
    public const TLS_OFF = 'off';
    public const TLS_REQUIRED = 'required';
    public const TLS_VERIFY_CA = 'verify_ca';
    public const TLS_VERIFY_FULL = 'verify_full';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 30)]
    private string $engine;

    #[ORM\Column(length: 255)]
    private string $host;

    #[ORM\Column]
    private int $port;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Agent $agent;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(length: 20)]
    private string $healthStatus = 'unknown';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $healthMessage = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(length: 20, options: ['default' => self::TLS_OFF])]
    private string $tlsMode = self::TLS_OFF;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $caCert = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $tags = [];

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $adminUser = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $encryptedAdminSecret = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $engine, string $host, int $port, Agent $agent)
    {
        $this->name = $name;
        $this->engine = $engine;
        $this->host = $host;
        $this->port = $port;
        $this->agent = $agent;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getEngine(): string
    {
        return $this->engine;
    }

    public function setEngine(string $engine): void
    {
        $this->engine = $engine;
        $this->touch();
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): void
    {
        $this->host = $host;
        $this->touch();
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): void
    {
        $this->port = $port;
        $this->touch();
    }

    public function getAgent(): Agent
    {
        return $this->agent;
    }

    public function setAgent(Agent $agent): void
    {
        $this->agent = $agent;
        $this->touch();
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
        $this->touch();
    }

    public function getHealthStatus(): string
    {
        return $this->healthStatus;
    }

    public function getHealthMessage(): ?string
    {
        return $this->healthMessage;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function getTlsMode(): string
    {
        return $this->tlsMode;
    }

    public function setTlsMode(string $tlsMode): void
    {
        $this->tlsMode = $tlsMode;
        $this->touch();
    }

    public function getCaCert(): ?string
    {
        return $this->caCert;
    }

    public function setCaCert(?string $caCert): void
    {
        $this->caCert = $caCert;
        $this->touch();
    }

    public function getTags(): array
    {
        return $this->tags ?? [];
    }

    public function setTags(array $tags): void
    {
        $this->tags = array_values(array_filter(array_map('trim', $tags), static fn (string $tag): bool => $tag !== ''));
        $this->touch();
    }

    public function getAdminUser(): ?string
    {
        return $this->adminUser;
    }

    public function setAdminUser(?string $adminUser): void
    {
        $this->adminUser = $adminUser;
        $this->touch();
    }

    public function getEncryptedAdminSecret(): ?array
    {
        return $this->encryptedAdminSecret;
    }

    public function setEncryptedAdminSecret(?array $encryptedAdminSecret): void
    {
        $this->encryptedAdminSecret = $encryptedAdminSecret;
        $this->touch();
    }

    public function markHealthy(?string $message = null): void
    {
        $this->healthStatus = 'healthy';
        $this->healthMessage = $message;
        $this->lastCheckedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function markUnhealthy(string $message): void
    {
        $this->healthStatus = 'unhealthy';
        $this->healthMessage = $message;
        $this->lastCheckedAt = new \DateTimeImmutable();
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
