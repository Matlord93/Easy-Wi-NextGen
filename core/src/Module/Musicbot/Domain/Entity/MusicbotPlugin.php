<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Repository\MusicbotPluginRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotPluginRepository::class)]
#[ORM\Table(name: 'musicbot_plugins')]
#[ORM\Index(name: 'idx_musicbot_plugins_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_musicbot_plugins_instance', columns: ['instance_id'])]
class MusicbotPlugin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $customer = null;

    #[ORM\ManyToOne(targetEntity: MusicbotInstance::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?MusicbotInstance $instance = null;

    #[ORM\Column(length: 120)]
    private string $identifier;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 40)]
    private string $version;

    #[ORM\Column(options: ['default' => false])]
    private bool $enabled = false;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $config = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $permissions = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $config @param array<string, mixed> $permissions */
    public function __construct(string $identifier, string $name, string $version, ?User $customer = null, ?MusicbotInstance $instance = null, array $config = [], array $permissions = [])
    {
        $this->identifier = $identifier;
        $this->name = $name;
        $this->version = $version;
        $this->customer = $customer;
        $this->instance = $instance;
        $this->config = $config;
        $this->permissions = $permissions;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): ?User { return $this->customer; }
    public function setCustomer(?User $customer): void { $this->customer = $customer; $this->touch(); }
    public function getInstance(): ?MusicbotInstance { return $this->instance; }
    public function setInstance(?MusicbotInstance $instance): void { $this->instance = $instance; $this->touch(); }
    public function getIdentifier(): string { return $this->identifier; }
    public function getPluginId(): string { return $this->identifier; }
    public function setIdentifier(string $identifier): void { $this->identifier = $identifier; $this->touch(); }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; $this->touch(); }
    public function getVersion(): string { return $this->version; }
    public function setVersion(string $version): void { $this->version = $version; $this->touch(); }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): void { $this->enabled = $enabled; $this->touch(); }
    /** @return array<string, mixed> */ public function getConfig(): array { return $this->config; }
    /** @return array<string, mixed> */ public function getSettings(): array { return $this->config; }
    /** @param array<string, mixed> $config */ public function setConfig(array $config): void { $this->config = $config; $this->touch(); }
    /** @param array<string, mixed> $settings */ public function setSettings(array $settings): void { $this->setConfig($settings); }
    /** @return array<string, mixed> */ public function getPermissions(): array { return $this->permissions; }
    /** @param array<string, mixed> $permissions */ public function setPermissions(array $permissions): void { $this->permissions = $permissions; $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
