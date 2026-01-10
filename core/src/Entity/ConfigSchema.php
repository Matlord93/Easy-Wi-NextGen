<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConfigSchemaRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConfigSchemaRepository::class)]
#[ORM\Table(name: 'config_schemas')]
#[ORM\Index(name: 'idx_config_schemas_game', columns: ['game_definition_id'])]
#[ORM\UniqueConstraint(name: 'uniq_config_schemas_game_key', columns: ['game_definition_id', 'config_key'])]
class ConfigSchema
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GameDefinition::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GameDefinition $gameDefinition;

    #[ORM\Column(length: 80, name: 'config_key')]
    private string $configKey;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 32)]
    private string $format;

    #[ORM\Column(length: 255, name: 'file_path')]
    private string $filePath;

    #[ORM\Column(type: 'json')]
    private array $schema;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        GameDefinition $gameDefinition,
        string $configKey,
        string $name,
        string $format,
        string $filePath,
        array $schema,
    ) {
        $this->gameDefinition = $gameDefinition;
        $this->configKey = $configKey;
        $this->name = $name;
        $this->format = $format;
        $this->filePath = $filePath;
        $this->schema = $schema;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGameDefinition(): GameDefinition
    {
        return $this->gameDefinition;
    }

    public function setGameDefinition(GameDefinition $gameDefinition): void
    {
        $this->gameDefinition = $gameDefinition;
        $this->touch();
    }

    public function getConfigKey(): string
    {
        return $this->configKey;
    }

    public function setConfigKey(string $configKey): void
    {
        $this->configKey = $configKey;
        $this->touch();
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

    public function getFormat(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): void
    {
        $this->format = $format;
        $this->touch();
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): void
    {
        $this->filePath = $filePath;
        $this->touch();
    }

    public function getSchema(): array
    {
        return $this->schema;
    }

    public function setSchema(array $schema): void
    {
        $this->schema = $schema;
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
