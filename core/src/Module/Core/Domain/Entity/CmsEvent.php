<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\CmsEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CmsEventRepository::class)]
#[ORM\Table(name: 'cms_events')]
#[ORM\Index(name: 'idx_cms_events_site_start', columns: ['site_id', 'start_at'])]
#[ORM\UniqueConstraint(name: 'uniq_cms_events_site_slug', columns: ['site_id', 'slug'])]
class CmsEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(length: 180)]
    private string $slug;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column]
    private \DateTimeImmutable $startAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 32)]
    private string $status = 'planned';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImagePath = null;

    #[ORM\Column]
    private bool $isPublished = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Site $site, string $title, string $slug, string $description, \DateTimeImmutable $startAt)
    {
        $this->site = $site;
        $this->title = trim($title);
        $this->slug = trim($slug);
        $this->description = $description;
        $this->startAt = $startAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getSite(): Site { return $this->site; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = trim($title); $this->touch(); }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = trim($slug); $this->touch(); }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): void { $this->description = $description; $this->touch(); }
    public function getStartAt(): \DateTimeImmutable { return $this->startAt; }
    public function setStartAt(\DateTimeImmutable $startAt): void { $this->startAt = $startAt; $this->touch(); }
    public function getEndAt(): ?\DateTimeImmutable { return $this->endAt; }
    public function setEndAt(?\DateTimeImmutable $endAt): void { $this->endAt = $endAt; $this->touch(); }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): void { $this->location = $location === null ? null : trim($location); $this->touch(); }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = trim($status) !== '' ? trim($status) : 'planned'; $this->touch(); }
    public function getCoverImagePath(): ?string { return $this->coverImagePath; }
    public function setCoverImagePath(?string $coverImagePath): void { $this->coverImagePath = $coverImagePath === null ? null : trim($coverImagePath); $this->touch(); }
    public function isPublished(): bool { return $this->isPublished; }
    public function setPublished(bool $isPublished): void { $this->isPublished = $isPublished; $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
