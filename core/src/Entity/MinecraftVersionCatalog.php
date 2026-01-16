<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MinecraftVersionCatalogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MinecraftVersionCatalogRepository::class)]
#[ORM\Table(name: 'minecraft_versions_catalog')]
#[ORM\UniqueConstraint(name: 'uniq_minecraft_versions_catalog', columns: ['channel', 'mc_version', 'build'])]
class MinecraftVersionCatalog
{
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

    public function __construct(
        string $channel,
        string $mcVersion,
        ?string $build,
        string $downloadUrl,
        ?string $sha256 = null,
        ?\DateTimeImmutable $releasedAt = null,
    ) {
        $this->channel = $channel;
        $this->mcVersion = $mcVersion;
        $this->build = $build;
        $this->downloadUrl = $downloadUrl;
        $this->sha256 = $sha256;
        $this->releasedAt = $releasedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getMcVersion(): string
    {
        return $this->mcVersion;
    }

    public function getBuild(): ?string
    {
        return $this->build;
    }

    public function setBuild(?string $build): void
    {
        $this->build = $build;
    }

    public function getDownloadUrl(): string
    {
        return $this->downloadUrl;
    }

    public function setDownloadUrl(string $downloadUrl): void
    {
        $this->downloadUrl = $downloadUrl;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    public function setSha256(?string $sha256): void
    {
        $this->sha256 = $sha256;
    }

    public function getReleasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function setReleasedAt(?\DateTimeImmutable $releasedAt): void
    {
        $this->releasedAt = $releasedAt;
    }
}
