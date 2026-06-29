<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Enum\MusicbotAutoDjMode;
use App\Repository\MusicbotAutoDjSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotAutoDjSettingsRepository::class)]
#[ORM\Table(name: 'musicbot_autodj_settings')]
#[ORM\UniqueConstraint(name: 'uniq_musicbot_autodj_instance', columns: ['instance_id'])]
#[ORM\Index(name: 'idx_musicbot_autodj_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'fk_autodj_playlist', columns: ['fallback_playlist_id'])]
class MusicbotAutoDjSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: MusicbotInstance::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotInstance $instance;

    #[ORM\Column]
    private bool $enabled = false;

    #[ORM\ManyToOne(targetEntity: MusicbotPlaylist::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MusicbotPlaylist $fallbackPlaylist = null;

    #[ORM\Column(enumType: MusicbotAutoDjMode::class, length: 30)]
    private MusicbotAutoDjMode $mode = MusicbotAutoDjMode::Random;

    #[ORM\Column]
    private bool $avoidRepeats = true;

    #[ORM\Column]
    private int $minQueueSize = 2;

    #[ORM\Column]
    private bool $shuffle = true;

    #[ORM\Column]
    private bool $repeat = false;

    #[ORM\Column]
    private int $idleSeconds = 60;

    #[ORM\Column(nullable: true)]
    private ?int $volumeOverride = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $timeWindowStart = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $timeWindowEnd = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $webradioFallbackUrl = null;

    #[ORM\Column]
    private bool $allowYoutube = false;

    #[ORM\Column]
    private bool $allowUploads = true;

    #[ORM\Column]
    private int $repeatProtectionWindow = 5;

    #[ORM\Column]
    private bool $avoidSameArtist = false;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $playlistIds = [];

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $genreFilter = null;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $lastPlayedTrackIds = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $customer, MusicbotInstance $instance)
    {
        $this->customer = $customer;
        $this->instance = $instance;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): User { return $this->customer; }
    public function getInstance(): MusicbotInstance { return $this->instance; }
    public function isEnabled(): bool { return $this->enabled; }
    public function getFallbackPlaylist(): ?MusicbotPlaylist { return $this->fallbackPlaylist; }
    public function getMode(): MusicbotAutoDjMode { return $this->mode; }
    public function isAvoidRepeats(): bool { return $this->avoidRepeats; }
    public function getMinQueueSize(): int { return $this->minQueueSize; }
    public function getGenreFilter(): ?string { return $this->genreFilter; }
    public function isShuffle(): bool { return $this->shuffle; }
    public function isRepeat(): bool { return $this->repeat; }
    public function getIdleSeconds(): int { return $this->idleSeconds; }
    public function getVolumeOverride(): ?int { return $this->volumeOverride; }
    public function getTimeWindowStart(): ?string { return $this->timeWindowStart; }
    public function getTimeWindowEnd(): ?string { return $this->timeWindowEnd; }
    public function getWebradioFallbackUrl(): ?string { return $this->webradioFallbackUrl; }
    public function isAllowYoutube(): bool { return $this->allowYoutube; }
    public function isAllowUploads(): bool { return $this->allowUploads; }
    public function getRepeatProtectionWindow(): int { return $this->repeatProtectionWindow; }
    public function isAvoidSameArtist(): bool { return $this->avoidSameArtist; }
    /** @return list<int> */ public function getPlaylistIds(): array { return $this->playlistIds; }
    /** @return list<int> */ public function getLastPlayedTrackIds(): array { return $this->lastPlayedTrackIds; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setEnabled(bool $enabled): void { $this->enabled = $enabled; $this->touch(); }
    public function setFallbackPlaylist(?MusicbotPlaylist $playlist): void { $this->fallbackPlaylist = $playlist; $this->touch(); }
    public function setMode(MusicbotAutoDjMode $mode): void { $this->mode = $mode; $this->touch(); }
    public function setAvoidRepeats(bool $avoidRepeats): void { $this->avoidRepeats = $avoidRepeats; $this->touch(); }
    public function setMinQueueSize(int $minQueueSize): void { $this->minQueueSize = max(1, min(50, $minQueueSize)); $this->touch(); }
    public function setGenreFilter(?string $genreFilter): void { $this->genreFilter = $genreFilter !== null && trim($genreFilter) !== '' ? trim($genreFilter) : null; $this->touch(); }
    public function setShuffle(bool $shuffle): void { $this->shuffle = $shuffle; $this->touch(); }
    public function setRepeat(bool $repeat): void { $this->repeat = $repeat; $this->touch(); }
    public function setIdleSeconds(int $idleSeconds): void { $this->idleSeconds = max(0, min(86400, $idleSeconds)); $this->touch(); }
    public function setVolumeOverride(?int $volumeOverride): void { $this->volumeOverride = $volumeOverride !== null ? max(0, min(100, $volumeOverride)) : null; $this->touch(); }
    public function setTimeWindowStart(?string $timeWindowStart): void { $this->timeWindowStart = self::normalizeTime($timeWindowStart); $this->touch(); }
    public function setTimeWindowEnd(?string $timeWindowEnd): void { $this->timeWindowEnd = self::normalizeTime($timeWindowEnd); $this->touch(); }
    public function setWebradioFallbackUrl(?string $url): void { $this->webradioFallbackUrl = $url !== null && trim($url) !== '' ? trim($url) : null; $this->touch(); }
    public function setAllowYoutube(bool $allowYoutube): void { $this->allowYoutube = $allowYoutube; $this->touch(); }
    public function setAllowUploads(bool $allowUploads): void { $this->allowUploads = $allowUploads; $this->touch(); }
    public function setRepeatProtectionWindow(int $window): void { $this->repeatProtectionWindow = max(0, min(100, $window)); $this->touch(); }
    public function setAvoidSameArtist(bool $avoidSameArtist): void { $this->avoidSameArtist = $avoidSameArtist; $this->touch(); }
    /** @param list<int> $ids */ public function setPlaylistIds(array $ids): void { $this->playlistIds = array_values(array_unique(array_filter($ids, 'is_int'))); $this->touch(); }
    /** @param list<int> $ids */ public function setLastPlayedTrackIds(array $ids): void { $this->lastPlayedTrackIds = array_values(array_filter($ids, 'is_int')); $this->touch(); }

    private static function normalizeTime(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : null;
    }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
