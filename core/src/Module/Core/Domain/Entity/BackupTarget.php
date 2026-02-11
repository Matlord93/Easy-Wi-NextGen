<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Enum\BackupDestinationType;
use App\Repository\BackupTargetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BackupTargetRepository::class)]
#[ORM\Table(name: 'backup_targets')]
class BackupTarget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\Column(enumType: BackupDestinationType::class)]
    private BackupDestinationType $type;

    #[ORM\Column(length: 160)]
    private string $label;

    #[ORM\Column(type: 'json')]
    private array $config;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $encryptedCredentials;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $customer,
        BackupDestinationType $type,
        string $label,
        array $config,
        ?array $encryptedCredentials = null,
        bool $enabled = true,
    ) {
        $this->customer = $customer;
        $this->type = $type;
        $this->label = $label;
        $this->config = $config;
        $this->encryptedCredentials = $encryptedCredentials;
        $this->enabled = $enabled;
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

    public function getType(): BackupDestinationType
    {
        return $this->type;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getEncryptedCredentials(): array
    {
        return $this->encryptedCredentials ?? [];
    }

    public function setType(BackupDestinationType $type): void
    {
        $this->type = $type;
        $this->touch();
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
        $this->touch();
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
        $this->touch();
    }

    public function setEncryptedCredentials(?array $encryptedCredentials): void
    {
        $this->encryptedCredentials = $encryptedCredentials;
        $this->enabled = $enabled;
        $this->touch();
    }

    public function hasEncryptedCredential(string $key): bool
    {
        $credentials = $this->encryptedCredentials ?? [];

        return array_key_exists($key, $credentials);
    }


    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
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
