<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Enum\TicketCategory;
use App\Repository\KnowledgeBaseArticleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KnowledgeBaseArticleRepository::class)]
#[ORM\Table(name: 'knowledge_base_articles')]
#[ORM\Index(name: 'idx_knowledge_base_site_id', columns: ['site_id'])]
#[ORM\Index(name: 'idx_knowledge_base_visibility', columns: ['visible_public'])]
#[ORM\Index(name: 'idx_knowledge_base_category', columns: ['category'])]
class KnowledgeBaseArticle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $siteId;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(length: 160)]
    private string $slug;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(enumType: TicketCategory::class)]
    private TicketCategory $category;

    #[ORM\Column]
    private bool $visiblePublic = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        int $siteId,
        string $title,
        string $slug,
        string $content,
        TicketCategory $category,
        bool $visiblePublic = false,
    ) {
        $this->siteId = $siteId;
        $this->title = $title;
        $this->slug = $slug;
        $this->content = $content;
        $this->category = $category;
        $this->visiblePublic = $visiblePublic;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
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

    public function getCategory(): TicketCategory
    {
        return $this->category;
    }

    public function setCategory(TicketCategory $category): void
    {
        $this->category = $category;
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
