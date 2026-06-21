<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
use App\Repository\MusicbotTrackRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotTrackRepository::class)]
#[ORM\Table(name: 'musicbot_tracks')]
#[ORM\Index(name: 'idx_musicbot_tracks_customer', columns: ['customer_id'])]
class MusicbotTrack
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: MusicbotInstance::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MusicbotInstance $instance = null;

    #[ORM\Column(length: 190)]
    private string $title;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $artist = null;

    #[ORM\Column]
    private int $durationSeconds;

    #[ORM\Column(enumType: MusicbotTrackSourceType::class)]
    private MusicbotTrackSourceType $sourceType;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column(length: 120)]
    private string $mimeType;

    #[ORM\Column(length: 64)]
    private string $sha256;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $metadata */
    public function __construct(User $customer, string $title, MusicbotTrackSourceType $sourceType, string $mimeType, string $sha256, int $durationSeconds = 0, array $metadata = [])
    {
        $this->customer = $customer;
        $this->title = $title;
        $this->sourceType = $sourceType;
        $this->mimeType = $mimeType;
        $this->sha256 = $sha256;
        $this->durationSeconds = max(0, $durationSeconds);
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): User { return $this->customer; }
    public function getInstance(): ?MusicbotInstance { return $this->instance; }
    public function setInstance(?MusicbotInstance $instance): void { $this->instance = $instance; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = $title; }
    public function getArtist(): ?string { return $this->artist; }
    public function setArtist(?string $artist): void { $this->artist = $artist; }
    public function getDurationSeconds(): int { return $this->durationSeconds; }
    public function setDurationSeconds(int $durationSeconds): void { $this->durationSeconds = max(0, $durationSeconds); }
    public function getSourceType(): MusicbotTrackSourceType { return $this->sourceType; }
    public function setSourceType(MusicbotTrackSourceType $sourceType): void { $this->sourceType = $sourceType; }
    public function getFilePath(): ?string { return $this->filePath; }
    public function setFilePath(?string $filePath): void { $this->filePath = $filePath; }
    public function getMimeType(): string { return $this->mimeType; }
    public function setMimeType(string $mimeType): void { $this->mimeType = $mimeType; }
    public function getSha256(): string { return $this->sha256; }
    public function setSha256(string $sha256): void { $this->sha256 = $sha256; }
    /** @return array<string, mixed> */ public function getMetadata(): array { return $this->metadata; }
    /** @param array<string, mixed> $metadata */ public function setMetadata(array $metadata): void { $this->metadata = $metadata; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
