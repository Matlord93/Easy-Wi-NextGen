<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\DownloadItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DownloadItemRepository::class)]
#[ORM\Table(name: 'download_items')]
#[ORM\Index(name: 'idx_download_items_site_id', columns: ['site_id'])]
#[ORM\Index(name: 'idx_download_items_visibility', columns: ['visible_public'])]
#[ORM\Index(name: 'idx_download_items_sort', columns: ['sort_order'])]
class DownloadItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $siteId;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255)]
    private string $url;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $version = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $fileSize = null;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column]
    private bool $visiblePublic = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        int $siteId,
        string $title,
        string $url,
        ?string $description = null,
        ?string $version = null,
        ?string $fileSize = null,
        bool $visiblePublic = false,
        int $sortOrder = 0,
    ) {
        $this->siteId = $siteId;
        $this->title = $title;
        $this->url = $url;
        $this->description = $description;
        $this->version = $version;
        $this->fileSize = $fileSize;
        $this->visiblePublic = $visiblePublic;
        $this->sortOrder = $sortOrder;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSiteId(): int
    {
        return $this->siteId;
    }

    public function setSiteId(int $siteId): void
    {
        $this->siteId = $siteId;
        $this->touch();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
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

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
        $this->touch();
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): void
    {
        $this->version = $version;
        $this->touch();
    }

    public function getFileSize(): ?string
    {
        return $this->fileSize;
    }

    public function setFileSize(?string $fileSize): void
    {
        $this->fileSize = $fileSize;
        $this->touch();
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
        $this->touch();
    }

    public function isVisiblePublic(): bool
    {
        return $this->visiblePublic;
    }

    public function setVisiblePublic(bool $visiblePublic): void
    {
        $this->visiblePublic = $visiblePublic;
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
