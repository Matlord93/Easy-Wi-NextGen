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
    /** @return list<int> */ public function getLastPlayedTrackIds(): array { return $this->lastPlayedTrackIds; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setEnabled(bool $enabled): void { $this->enabled = $enabled; $this->touch(); }
    public function setFallbackPlaylist(?MusicbotPlaylist $playlist): void { $this->fallbackPlaylist = $playlist; $this->touch(); }
    public function setMode(MusicbotAutoDjMode $mode): void { $this->mode = $mode; $this->touch(); }
    public function setAvoidRepeats(bool $avoidRepeats): void { $this->avoidRepeats = $avoidRepeats; $this->touch(); }
    public function setMinQueueSize(int $minQueueSize): void { $this->minQueueSize = max(1, min(50, $minQueueSize)); $this->touch(); }
    public function setGenreFilter(?string $genreFilter): void { $this->genreFilter = $genreFilter !== null && trim($genreFilter) !== '' ? trim($genreFilter) : null; $this->touch(); }
    /** @param list<int> $ids */ public function setLastPlayedTrackIds(array $ids): void { $this->lastPlayedTrackIds = array_values(array_filter($ids, 'is_int')); $this->touch(); }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
