<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\VoiceNodeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VoiceNodeRepository::class)]
#[ORM\Table(name: 'voice_nodes')]
class VoiceNode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $providerType;

    #[ORM\Column(length: 255)]
    private string $host;

    #[ORM\Column]
    private int $queryPort;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $credentialsEncrypted = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $providerType, string $host, int $queryPort)
    {
        $this->name = $name;
        $this->providerType = $providerType;
        $this->host = $host;
        $this->queryPort = $queryPort;
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
    public function getProviderType(): string
    {
        return $this->providerType;
    }
    public function getHost(): string
    {
        return $this->host;
    }
    public function getQueryPort(): int
    {
        return $this->queryPort;
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
    public function setCredentialsEncrypted(?array $credentialsEncrypted): void
    {
        $this->credentialsEncrypted = $credentialsEncrypted;
        $this->touch();
    }
    public function getCredentialsEncrypted(): ?array
    {
        return $this->credentialsEncrypted;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
