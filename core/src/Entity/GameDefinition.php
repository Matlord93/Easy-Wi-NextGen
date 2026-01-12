<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameDefinitionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GameDefinitionRepository::class)]
#[ORM\Table(name: 'game_definitions')]
#[ORM\UniqueConstraint(name: 'uniq_game_definitions_key', columns: ['game_key'])]
class GameDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80, name: 'game_key')]
    private string $gameKey;

    #[ORM\Column(length: 120, name: 'display_name')]
    private string $displayName;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $gameKey, string $displayName, ?string $description = null)
    {
        $this->gameKey = $gameKey;
        $this->displayName = $displayName;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGameKey(): string
    {
        return $this->gameKey;
    }

    public function setGameKey(string $gameKey): void
    {
        $this->gameKey = $gameKey;
        $this->touch();
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
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
