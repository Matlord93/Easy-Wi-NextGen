<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ForumBoardRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ForumBoardRepository::class)]
#[ORM\Table(name: 'forum_boards')]
#[ORM\UniqueConstraint(name: 'uniq_forum_boards_site_slug', columns: ['site_id', 'slug'])]
#[ORM\Index(name: 'idx_forum_boards_category', columns: ['category_id'])]
class ForumBoard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\ManyToOne(targetEntity: ForumCategory::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ForumCategory $category;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(length: 160)]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Site $site, ForumCategory $category, string $title, string $slug)
    {
        $this->site = $site;
        $this->category = $category;
        $this->title = $title;
        $this->slug = $slug;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSite(): Site { return $this->site; }
    public function getCategory(): ForumCategory { return $this->category; }
    public function setCategory(ForumCategory $category): void { $this->category = $category; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = trim($title); }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = trim($slug); }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): void { $this->sortOrder = $sortOrder; }
    public function isActive(): bool { return $this->isActive; }
    public function setActive(bool $isActive): void { $this->isActive = $isActive; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
