<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\BlogTagRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BlogTagRepository::class)]
#[ORM\Table(name: 'blog_tags')]
#[ORM\Index(name: 'idx_blog_tags_site_id', columns: ['site_id'])]
#[ORM\UniqueConstraint(name: 'uniq_blog_tags_site_slug', columns: ['site_id', 'slug'])]
class BlogTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\Column(length: 140)]
    private string $name;

    #[ORM\Column(length: 140)]
    private string $slug;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Site $site, string $name, string $slug)
    {
        $this->site = $site;
        $this->name = $name;
        $this->slug = $slug;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): Site
    {
        return $this->site;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = trim($slug);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
