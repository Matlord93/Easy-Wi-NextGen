<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TemplateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TemplateRepository::class)]
#[ORM\Table(name: 'game_templates')]
class Template
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text')]
    private string $startParams;

    #[ORM\Column(type: 'json')]
    private array $requiredPorts;

    #[ORM\Column(type: 'text')]
    private string $installCommand;

    #[ORM\Column(type: 'text')]
    private string $updateCommand;

    #[ORM\Column(type: 'json')]
    private array $allowedSwitchFlags;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $name,
        ?string $description,
        string $startParams,
        array $requiredPorts,
        string $installCommand,
        string $updateCommand,
        array $allowedSwitchFlags,
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->startParams = $startParams;
        $this->requiredPorts = $requiredPorts;
        $this->installCommand = $installCommand;
        $this->updateCommand = $updateCommand;
        $this->allowedSwitchFlags = $allowedSwitchFlags;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function getStartParams(): string
    {
        return $this->startParams;
    }

    public function setStartParams(string $startParams): void
    {
        $this->startParams = $startParams;
        $this->touch();
    }

    public function getRequiredPorts(): array
    {
        return $this->requiredPorts;
    }

    public function setRequiredPorts(array $requiredPorts): void
    {
        $this->requiredPorts = $requiredPorts;
        $this->touch();
    }

    public function getInstallCommand(): string
    {
        return $this->installCommand;
    }

    public function setInstallCommand(string $installCommand): void
    {
        $this->installCommand = $installCommand;
        $this->touch();
    }

    public function getUpdateCommand(): string
    {
        return $this->updateCommand;
    }

    public function setUpdateCommand(string $updateCommand): void
    {
        $this->updateCommand = $updateCommand;
        $this->touch();
    }

    public function getAllowedSwitchFlags(): array
    {
        return $this->allowedSwitchFlags;
    }

    public function setAllowedSwitchFlags(array $allowedSwitchFlags): void
    {
        $this->allowedSwitchFlags = $allowedSwitchFlags;
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
