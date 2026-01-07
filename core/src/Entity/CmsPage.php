<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CmsPageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CmsPageRepository::class)]
#[ORM\Table(name: 'cms_pages')]
class CmsPage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(length: 160)]
    private string $slug;

    #[ORM\Column]
    private bool $isPublished = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, CmsBlock>
     */
    #[ORM\OneToMany(mappedBy: 'page', targetEntity: CmsBlock::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $blocks;

    public function __construct(string $title, string $slug, bool $isPublished)
    {
        $this->title = $title;
        $this->slug = $slug;
        $this->isPublished = $isPublished;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->blocks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function isPublished(): bool
    {
        return $this->isPublished;
    }

    public function setPublished(bool $isPublished): void
    {
        $this->isPublished = $isPublished;
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

    /**
     * @return Collection<int, CmsBlock>
     */
    public function getBlocks(): Collection
    {
        return $this->blocks;
    }

    public function addBlock(CmsBlock $block): void
    {
        if (!$this->blocks->contains($block)) {
            $this->blocks->add($block);
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
