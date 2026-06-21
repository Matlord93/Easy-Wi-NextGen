<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Repository\MusicbotQueueItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotQueueItemRepository::class)]
#[ORM\Table(name: 'musicbot_queue_items')]
#[ORM\Index(name: 'idx_musicbot_queue_instance_position', columns: ['instance_id', 'position'])]
#[ORM\Index(name: 'idx_musicbot_queue_track', columns: ['track_id'])]
#[ORM\Index(name: 'idx_musicbot_queue_requested_by', columns: ['requested_by_id'])]
class MusicbotQueueItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MusicbotInstance::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotInstance $instance;

    #[ORM\ManyToOne(targetEntity: MusicbotTrack::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotTrack $track;

    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedBy = null;

    #[ORM\Column(length: 32)]
    private string $status = 'queued';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(MusicbotInstance $instance, MusicbotTrack $track, int $position, ?User $requestedBy = null)
    {
        $this->instance = $instance;
        $this->track = $track;
        $this->position = max(0, $position);
        $this->requestedBy = $requestedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getInstance(): MusicbotInstance { return $this->instance; }
    public function getTrack(): MusicbotTrack { return $this->track; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): void { $this->position = max(0, $position); }
    public function getRequestedBy(): ?User { return $this->requestedBy; }
    public function setRequestedBy(?User $requestedBy): void { $this->requestedBy = $requestedBy; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
