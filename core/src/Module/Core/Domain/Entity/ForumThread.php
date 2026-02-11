<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ForumThreadRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ForumThreadRepository::class)]
#[ORM\Table(name: 'forum_threads')]
#[ORM\Index(name: 'idx_forum_threads_board_lastpost', columns: ['board_id', 'last_post_at'])]
#[ORM\Index(name: 'idx_forum_threads_board_activity', columns: ['board_id', 'last_activity_at'])]
class ForumThread
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\ManyToOne(targetEntity: ForumBoard::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ForumBoard $board;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $authorUser = null;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(length: 180)]
    private string $slug;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPinned = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isClosed = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastPostAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastActivityAt;

    public function __construct(Site $site, ForumBoard $board, ?User $authorUser, string $title, string $slug)
    {
        $this->site = $site;
        $this->board = $board;
        $this->authorUser = $authorUser;
        $this->title = $title;
        $this->slug = $slug;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->lastPostAt = $this->createdAt;
        $this->lastActivityAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getSite(): Site { return $this->site; }
    public function getBoard(): ForumBoard { return $this->board; }
    public function getAuthorUser(): ?User { return $this->authorUser; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = trim($title); $this->touch(); }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = trim($slug); $this->touch(); }
    public function isPinned(): bool { return $this->isPinned; }
    public function setPinned(bool $isPinned): void { $this->isPinned = $isPinned; $this->touch(); }
    public function isClosed(): bool { return $this->isClosed; }
    public function setClosed(bool $isClosed): void { $this->isClosed = $isClosed; $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getLastPostAt(): \DateTimeImmutable { return $this->lastPostAt; }
    public function getLastActivityAt(): \DateTimeImmutable { return $this->lastActivityAt; }
    public function markPostActivity(): void { $this->lastPostAt = new \DateTimeImmutable(); $this->lastActivityAt = $this->lastPostAt; $this->touch(); }
    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
