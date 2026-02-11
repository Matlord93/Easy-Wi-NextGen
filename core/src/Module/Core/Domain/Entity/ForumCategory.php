<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ForumCategoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ForumCategoryRepository::class)]
#[ORM\Table(name: 'forum_categories')]
#[ORM\UniqueConstraint(name: 'uniq_forum_categories_site_slug', columns: ['site_id', 'slug'])]
#[ORM\Index(name: 'idx_forum_categories_site_sort', columns: ['site_id', 'sort_order'])]
class ForumCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(length: 160)]
    private string $slug;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Site $site, string $title, string $slug)
    {
        $this->site = $site;
        $this->title = $title;
        $this->slug = $slug;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSite(): Site { return $this->site; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = trim($title); }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = trim($slug); }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): void { $this->sortOrder = $sortOrder; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
