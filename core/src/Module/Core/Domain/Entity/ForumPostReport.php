<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ForumPostReportRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ForumPostReportRepository::class)]
#[ORM\Table(name: 'forum_post_reports')]
#[ORM\Index(name: 'idx_forum_reports_status_created', columns: ['status', 'created_at'])]
class ForumPostReport
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumPost::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ForumPost $post;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reporter = null;

    #[ORM\Column(length: 120)]
    private string $reason;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $reporterIpHash = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvedBy = null;

    public function __construct(ForumPost $post, ?User $reporter, string $reason)
    {
        $this->post = $post;
        $this->reporter = $reporter;
        $this->reason = trim($reason);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getPost(): ForumPost
    {
        return $this->post;
    }
    public function getReporter(): ?User
    {
        return $this->reporter;
    }
    public function getReason(): string
    {
        return $this->reason;
    }
    public function getDetails(): ?string
    {
        return $this->details;
    }
    public function setDetails(?string $details): void
    {
        $this->details = $details !== null ? trim($details) : null;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function getReporterIpHash(): ?string
    {
        return $this->reporterIpHash;
    }
    public function setReporterIpHash(?string $reporterIpHash): void
    {
        $this->reporterIpHash = $reporterIpHash;
    }
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }
    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function resolve(?User $admin): void
    {
        $this->status = self::STATUS_RESOLVED;
        $this->resolvedAt = new \DateTimeImmutable();
        $this->resolvedBy = $admin;
    }
}
