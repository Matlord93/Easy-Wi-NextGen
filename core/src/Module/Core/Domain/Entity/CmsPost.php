<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\CmsPostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CmsPostRepository::class)]
#[ORM\Table(name: 'cms_posts')]
#[ORM\Index(name: 'idx_cms_posts_site_id', columns: ['site_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cms_posts_site_slug', columns: ['site_id', 'slug'])]
class CmsPost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Site $site;

    #[ORM\ManyToOne(targetEntity: BlogCategory::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?BlogCategory $category = null;

    /**
     * @var Collection<int, BlogTag>
     */
    #[ORM\ManyToMany(targetEntity: BlogTag::class)]
    #[ORM\JoinTable(name: 'blog_post_tags')]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $tags;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(length: 180)]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $excerpt = null;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $seoTitle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $seoDescription = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $featuredImagePath = null;

    #[ORM\Column]
    private bool $isPublished = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Site $site, string $title, string $slug, string $content, ?string $excerpt, bool $isPublished)
    {
        $this->site = $site;
        $this->tags = new ArrayCollection();
        $this->title = $title;
        $this->slug = $slug;
        $this->content = $content;
        $this->excerpt = $excerpt;
        $this->isPublished = $isPublished;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;

        if ($isPublished) {
            $this->publishedAt = $this->createdAt;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): Site
    {
        return $this->site;
    }

    public function getCategory(): ?BlogCategory
    {
        return $this->category;
    }

    public function setCategory(?BlogCategory $category): void
    {
        $this->category = $category;
        $this->touch();
    }

    /**
     * @return Collection<int, BlogTag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function clearTags(): void
    {
        $this->tags->clear();
        $this->touch();
    }

    public function addTag(BlogTag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
            $this->touch();
        }
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
        $this->touch();
    }

    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }

    public function setExcerpt(?string $excerpt): void
    {
        $this->excerpt = $excerpt;
        $this->touch();
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
        $this->touch();
    }

    public function getSeoTitle(): ?string
    {
        return $this->seoTitle;
    }

    public function setSeoTitle(?string $seoTitle): void
    {
        $seoTitle = $seoTitle === null ? null : trim($seoTitle);
        $this->seoTitle = $seoTitle === '' ? null : $seoTitle;
        $this->touch();
    }

    public function getSeoDescription(): ?string
    {
        return $this->seoDescription;
    }

    public function setSeoDescription(?string $seoDescription): void
    {
        $seoDescription = $seoDescription === null ? null : trim($seoDescription);
        $this->seoDescription = $seoDescription === '' ? null : $seoDescription;
        $this->touch();
    }

    public function getFeaturedImagePath(): ?string
    {
        return $this->featuredImagePath;
    }

    public function setFeaturedImagePath(?string $featuredImagePath): void
    {
        $featuredImagePath = $featuredImagePath === null ? null : trim($featuredImagePath);
        $this->featuredImagePath = $featuredImagePath === '' ? null : $featuredImagePath;
        $this->touch();
    }

    public function isPublished(): bool
    {
        return $this->isPublished;
    }

    public function setPublished(bool $isPublished): void
    {
        $this->isPublished = $isPublished;
        if ($isPublished && $this->publishedAt === null) {
            $this->publishedAt = new \DateTimeImmutable();
        }
        if (!$isPublished) {
            $this->publishedAt = null;
        }
        $this->touch();
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
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
