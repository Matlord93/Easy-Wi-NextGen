<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ModuleSettingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ModuleSettingRepository::class)]
#[ORM\Table(name: 'module_settings')]
class ModuleSetting
{
    #[ORM\Id]
    #[ORM\Column(length: 40)]
    private string $moduleKey;

    #[ORM\Column(length: 20)]
    private string $version;

    #[ORM\Column]
    private bool $enabled;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $moduleKey, string $version, bool $enabled)
    {
        $this->moduleKey = $moduleKey;
        $this->version = $version;
        $this->enabled = $enabled;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getModuleKey(): string
    {
        return $this->moduleKey;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): void
    {
        $this->version = $version;
        $this->touch();
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
