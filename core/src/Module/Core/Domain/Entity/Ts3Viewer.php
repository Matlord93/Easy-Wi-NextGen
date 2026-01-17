<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\Ts3ViewerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: Ts3ViewerRepository::class)]
#[ORM\Table(name: 'ts3_viewers')]
class Ts3Viewer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Ts3VirtualServer::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Ts3VirtualServer $virtualServer;

    #[ORM\Column(length: 64, unique: true)]
    private string $publicId;

    #[ORM\Column]
    private bool $enabled = false;

    #[ORM\Column]
    private int $cacheTtlMs = 1500;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $domainAllowlist = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Ts3VirtualServer $virtualServer, string $publicId)
    {
        $this->virtualServer = $virtualServer;
        $this->publicId = $publicId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVirtualServer(): Ts3VirtualServer
    {
        return $this->virtualServer;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
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

    public function getCacheTtlMs(): int
    {
        return $this->cacheTtlMs;
    }

    public function setCacheTtlMs(int $cacheTtlMs): void
    {
        $this->cacheTtlMs = max(250, $cacheTtlMs);
        $this->touch();
    }

    public function getDomainAllowlist(): ?string
    {
        return $this->domainAllowlist;
    }

    public function setDomainAllowlist(?string $domainAllowlist): void
    {
        $this->domainAllowlist = $domainAllowlist;
        $this->touch();
    }

    /**
     * @return string[]
     */
    public function getDomainAllowlistEntries(): array
    {
        if ($this->domainAllowlist === null || trim($this->domainAllowlist) === '') {
            return [];
        }

        $lines = preg_split('/\r?\n/', $this->domainAllowlist);
        if ($lines === false) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $lines), static fn (string $entry) => $entry !== ''));
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
