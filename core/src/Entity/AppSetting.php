<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppSettingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
#[ORM\Table(name: 'app_settings')]
class AppSetting
{
    #[ORM\Id]
    #[ORM\Column(length: 80)]
    private string $settingKey;

    #[ORM\Column(type: 'json', nullable: true)]
    private mixed $value;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $settingKey, mixed $value)
    {
        $this->settingKey = $settingKey;
        $this->value = $value;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getSettingKey(): string
    {
        return $this->settingKey;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): void
    {
        $this->value = $value;
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
