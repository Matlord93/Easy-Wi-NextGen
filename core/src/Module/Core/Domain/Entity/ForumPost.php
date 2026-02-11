<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ForumPostRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ForumPostRepository::class)]
#[ORM\Table(name: 'forum_posts')]
#[ORM\Index(name: 'idx_forum_posts_thread_created', columns: ['thread_id', 'created_at'])]
class ForumPost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\ManyToOne(targetEntity: ForumThread::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ForumThread $thread;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $authorUser = null;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(options: ['default' => false])]
    private bool $isDeleted = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $deletedBy = null;

    public function __construct(Site $site, ForumThread $thread, ?User $authorUser, string $content)
    {
        $this->site = $site;
        $this->thread = $thread;
        $this->authorUser = $authorUser;
        $this->content = trim($content);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getSite(): Site
    {
        return $this->site;
    }
    public function getThread(): ForumThread
    {
        return $this->thread;
    }
    public function getAuthorUser(): ?User
    {
        return $this->authorUser;
    }
    public function getContent(): string
    {
        return $this->content;
    }
    public function setContent(string $content): void
    {
        $this->content = trim($content);
        $this->updatedAt = new \DateTimeImmutable();
    }
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
    public function isDeleted(): bool
    {
        return $this->isDeleted;
    }
    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }
    public function getDeletedBy(): ?User
    {
        return $this->deletedBy;
    }
    public function setDeleted(bool $isDeleted, ?User $actor = null): void
    {
        $this->isDeleted = $isDeleted;
        $this->deletedAt = $isDeleted ? new \DateTimeImmutable() : null;
        $this->deletedBy = $isDeleted ? $actor : null;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
