<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\GamePluginRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GamePluginRepository::class)]
#[ORM\Table(name: 'game_template_plugins')]
#[ORM\Index(name: 'idx_game_template_plugins_template', columns: ['template_id'])]
class GamePlugin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Template::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private Template $template;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 80)]
    private string $version;

    #[ORM\Column(length: 128)]
    private string $checksum;

    #[ORM\Column(length: 255)]
    private string $downloadUrl;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Template $template,
        string $name,
        string $version,
        string $checksum,
        string $downloadUrl,
        ?string $description = null,
    ) {
        $this->template = $template;
        $this->name = $name;
        $this->version = $version;
        $this->checksum = $checksum;
        $this->downloadUrl = $downloadUrl;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): Template
    {
        return $this->template;
    }

    public function setTemplate(Template $template): void
    {
        $this->template = $template;
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

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): void
    {
        $this->version = $version;
        $this->touch();
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function setChecksum(string $checksum): void
    {
        $this->checksum = $checksum;
        $this->touch();
    }

    public function getDownloadUrl(): string
    {
        return $this->downloadUrl;
    }

    public function setDownloadUrl(string $downloadUrl): void
    {
        $this->downloadUrl = $downloadUrl;
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
