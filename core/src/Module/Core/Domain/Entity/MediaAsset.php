<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MediaAssetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MediaAssetRepository::class)]
#[ORM\Table(name: 'media_assets')]
#[ORM\Index(name: 'idx_media_assets_site_id', columns: ['site_id'])]
class MediaAsset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Site $site = null;

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $alt = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mime = null;

    #[ORM\Column(nullable: true)]
    private ?int $size = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSite(): ?Site { return $this->site; }
    public function setSite(?Site $site): void { $this->site = $site; }
    public function getPath(): string { return $this->path; }
    public function setPath(string $path): void { $this->path = trim($path); }
    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): void { $this->title = $title === null ? null : trim($title); }
    public function getAlt(): ?string { return $this->alt; }
    public function setAlt(?string $alt): void { $this->alt = $alt === null ? null : trim($alt); }
    public function getMime(): ?string { return $this->mime; }
    public function setMime(?string $mime): void { $this->mime = $mime; }
    public function getSize(): ?int { return $this->size; }
    public function setSize(?int $size): void { $this->size = $size; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
