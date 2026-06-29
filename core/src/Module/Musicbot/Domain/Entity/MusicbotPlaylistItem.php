<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Repository\MusicbotPlaylistItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotPlaylistItemRepository::class)]
#[ORM\Table(name: 'musicbot_playlist_items')]
#[ORM\Index(name: 'idx_musicbot_playlist_items_playlist_position', columns: ['playlist_id', 'position'])]
#[ORM\Index(name: 'idx_musicbot_playlist_items_track', columns: ['track_id'])]
class MusicbotPlaylistItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MusicbotPlaylist::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotPlaylist $playlist;

    #[ORM\ManyToOne(targetEntity: MusicbotTrack::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotTrack $track;

    #[ORM\Column]
    private int $position;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    public function __construct(MusicbotPlaylist $playlist, MusicbotTrack $track, int $position)
    {
        $this->playlist = $playlist;
        $this->track = $track;
        $this->position = max(0, $position);
    }

    public function getId(): ?int { return $this->id; }
    public function getPlaylist(): MusicbotPlaylist { return $this->playlist; }
    public function getTrack(): MusicbotTrack { return $this->track; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): void { $this->position = max(0, $position); }
    /** @return array<string, mixed> */ public function getMetadata(): array { return $this->metadata; }
    /** @param array<string, mixed> $metadata */ public function setMetadata(array $metadata): void { $this->metadata = $metadata; }
}
