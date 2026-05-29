<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MinecraftVersionCatalogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MinecraftVersionCatalogRepository::class)]
#[ORM\Table(name: 'minecraft_versions_catalog')]
#[ORM\UniqueConstraint(name: 'uniq_minecraft_versions_catalog', columns: ['channel', 'mc_version', 'build'])]
class MinecraftVersionCatalog
{
    public const CHANNELS = ['vanilla', 'paper', 'bedrock'];
    public const SOURCES = ['import', 'manual'];
    public const JAVA_VERSIONS = ['8', '16', '17', '21'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $channel;

    #[ORM\Column(length: 32, name: 'mc_version')]
    private string $mcVersion;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $build = null;

    #[ORM\Column(type: 'text', name: 'download_url')]
    private string $downloadUrl;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sha256 = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $source = 'import';

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $javaVersion = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $channel,
        string $mcVersion,
        ?string $build,
        string $downloadUrl,
        ?string $sha256 = null,
        ?\DateTimeImmutable $releasedAt = null,
    ) {
        $now = new \DateTimeImmutable();
        $this->channel = $channel;
        $this->mcVersion = $mcVersion;
        $this->build = $build;
        $this->downloadUrl = $downloadUrl;
        $this->sha256 = $sha256;
        $this->releasedAt = $releasedAt;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getChannel(): string { return $this->channel; }
    public function setChannel(string $channel): void { $this->channel = $channel; $this->touch(); }
    public function getMcVersion(): string { return $this->mcVersion; }
    public function setMcVersion(string $mcVersion): void { $this->mcVersion = $mcVersion; $this->touch(); }
    public function getBuild(): ?string { return $this->build; }
    public function setBuild(?string $build): void { $this->build = $build; $this->touch(); }
    public function getDownloadUrl(): string { return $this->downloadUrl; }
    public function setDownloadUrl(string $downloadUrl): void { $this->downloadUrl = $downloadUrl; $this->touch(); }
    public function getSha256(): ?string { return $this->sha256; }
    public function setSha256(?string $sha256): void { $this->sha256 = $sha256; $this->touch(); }
    public function getReleasedAt(): ?\DateTimeImmutable { return $this->releasedAt; }
    public function setReleasedAt(?\DateTimeImmutable $releasedAt): void { $this->releasedAt = $releasedAt; $this->touch(); }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): void { $this->isActive = $isActive; $this->touch(); }
    public function getSource(): ?string { return $this->source; }
    public function setSource(?string $source): void { $this->source = $source; $this->touch(); }
    public function getJavaVersion(): ?string { return $this->javaVersion; }
    public function setJavaVersion(?string $javaVersion): void { $this->javaVersion = $javaVersion; $this->touch(); }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): void { $this->notes = $notes; $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
