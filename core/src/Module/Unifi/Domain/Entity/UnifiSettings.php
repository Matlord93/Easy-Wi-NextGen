<?php

declare(strict_types=1);

namespace App\Module\Unifi\Domain\Entity;

use App\Module\Unifi\Infrastructure\Repository\UnifiSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UnifiSettingsRepository::class)]
#[ORM\Table(name: 'unifi_settings')]
class UnifiSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $enabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $baseUrl = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $passwordEncrypted = null;

    #[ORM\Column]
    private bool $verifyTls = true;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $site = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $nodeTargets = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getBaseUrl(): string
    {
        return $this->baseUrl ?? '';
    }

    public function setBaseUrl(?string $baseUrl): void
    {
        $baseUrl = $baseUrl === null ? null : trim($baseUrl);
        $this->baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : null;
        $this->touch();
    }

    public function getUsername(): string
    {
        return $this->username ?? '';
    }

    public function setUsername(?string $username): void
    {
        $username = $username === null ? null : trim($username);
        $this->username = $username !== '' ? $username : null;
        $this->touch();
    }

    /**
     * @return array{key_id: string, nonce: string, ciphertext: string}|null
     */
    public function getPasswordEncrypted(): ?array
    {
        return $this->passwordEncrypted;
    }

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string}|null $payload
     */
    public function setPasswordEncrypted(?array $payload): void
    {
        $this->passwordEncrypted = $payload;
        $this->touch();
    }

    public function isVerifyTls(): bool
    {
        return $this->verifyTls;
    }

    public function setVerifyTls(bool $verifyTls): void
    {
        $this->verifyTls = $verifyTls;
        $this->touch();
    }

    public function getSite(): string
    {
        return $this->site ?? 'default';
    }

    public function setSite(?string $site): void
    {
        $site = $site === null ? null : trim($site);
        $this->site = $site !== '' ? $site : null;
        $this->touch();
    }

    /**
     * @return array<string, string>
     */
    public function getNodeTargets(): array
    {
        return is_array($this->nodeTargets) ? $this->nodeTargets : [];
    }

    /**
     * @param array<string, string> $nodeTargets
     */
    public function setNodeTargets(array $nodeTargets): void
    {
        $this->nodeTargets = $nodeTargets === [] ? null : $nodeTargets;
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
