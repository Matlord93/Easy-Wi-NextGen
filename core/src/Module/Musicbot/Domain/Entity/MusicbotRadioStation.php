<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Repository\MusicbotRadioStationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotRadioStationRepository::class)]
#[ORM\Table(name: 'musicbot_radio_stations')]
#[ORM\Index(name: 'idx_musicbot_radio_stations_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_musicbot_radio_stations_instance', columns: ['instance_id'])]
#[ORM\Index(name: 'idx_musicbot_radio_stations_global', columns: ['is_global'])]
#[ORM\Index(name: 'idx_musicbot_radio_stations_active', columns: ['is_active'])]
class MusicbotRadioStation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Null for global catalog stations; set for customer-private stations. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $customer;

    #[ORM\ManyToOne(targetEntity: MusicbotInstance::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MusicbotInstance $instance = null;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(length: 2048)]
    private string $streamUrl;

    /** Cached resolved direct stream URL (after M3U/PLS/XSPF resolution). */
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $resolvedStreamUrl = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $genre = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $homepage = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $logoUrl = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $language = null;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    private array $tags = [];

    /** Stream bitrate in kbps. */
    #[ORM\Column(nullable: true)]
    private ?int $bitrate = null;

    /** Format identifier: mp3, aac, aac+, ogg, opus, flac. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $format = null;

    /** True = part of global catalog visible to all customers. */
    #[ORM\Column]
    private bool $isGlobal = false;

    /** False = stream is known broken or disabled by admin. */
    #[ORM\Column]
    private bool $isActive = true;

    /** Kept for backward compat; use MusicbotRadioFavorite for new code. */
    #[ORM\Column]
    private bool $isFavorite = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastPlayedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?User $customer, string $name, string $streamUrl, bool $isGlobal = false)
    {
        $this->customer = $customer;
        $this->name = $name;
        $this->streamUrl = $streamUrl;
        $this->isGlobal = $isGlobal;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): ?User { return $this->customer; }
    public function setCustomer(?User $customer): void { $this->customer = $customer; $this->touch(); }
    public function getInstance(): ?MusicbotInstance { return $this->instance; }
    public function setInstance(?MusicbotInstance $instance): void { $this->instance = $instance; $this->touch(); }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; $this->touch(); }
    public function getStreamUrl(): string { return $this->streamUrl; }
    public function setStreamUrl(string $streamUrl): void { $this->streamUrl = $streamUrl; $this->touch(); }
    public function getResolvedStreamUrl(): ?string { return $this->resolvedStreamUrl; }
    public function setResolvedStreamUrl(?string $resolvedStreamUrl): void { $this->resolvedStreamUrl = $resolvedStreamUrl; $this->touch(); }
    public function getGenre(): ?string { return $this->genre; }
    public function setGenre(?string $genre): void { $this->genre = $genre; $this->touch(); }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description; $this->touch(); }
    public function getHomepage(): ?string { return $this->homepage; }
    public function setHomepage(?string $homepage): void { $this->homepage = $homepage; $this->touch(); }
    public function getLogoUrl(): ?string { return $this->logoUrl; }
    public function setLogoUrl(?string $logoUrl): void { $this->logoUrl = $logoUrl; $this->touch(); }
    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $country): void { $this->country = $country; $this->touch(); }
    public function getLanguage(): ?string { return $this->language; }
    public function setLanguage(?string $language): void { $this->language = $language; $this->touch(); }
    /** @return string[] */ public function getTags(): array { return $this->tags; }
    /** @param string[] $tags */ public function setTags(array $tags): void { $this->tags = array_values(array_unique(array_filter(array_map('trim', $tags)))); $this->touch(); }
    public function getBitrate(): ?int { return $this->bitrate; }
    public function setBitrate(?int $bitrate): void { $this->bitrate = $bitrate; $this->touch(); }
    public function getFormat(): ?string { return $this->format; }
    public function setFormat(?string $format): void { $this->format = $format; $this->touch(); }
    public function isGlobal(): bool { return $this->isGlobal; }
    public function setGlobal(bool $isGlobal): void { $this->isGlobal = $isGlobal; $this->touch(); }
    public function isActive(): bool { return $this->isActive; }
    public function setActive(bool $isActive): void { $this->isActive = $isActive; $this->touch(); }
    public function isFavorite(): bool { return $this->isFavorite; }
    public function setFavorite(bool $isFavorite): void { $this->isFavorite = $isFavorite; $this->touch(); }
    public function getLastPlayedAt(): ?\DateTimeImmutable { return $this->lastPlayedAt; }
    public function setLastPlayedAt(?\DateTimeImmutable $lastPlayedAt): void { $this->lastPlayedAt = $lastPlayedAt; $this->touch(); }
    public function getLastCheckedAt(): ?\DateTimeImmutable { return $this->lastCheckedAt; }
    public function setLastCheckedAt(?\DateTimeImmutable $lastCheckedAt): void { $this->lastCheckedAt = $lastCheckedAt; $this->touch(); }
    /** @return array<string, mixed> */ public function getMetadata(): array { return $this->metadata; }
    /** @param array<string, mixed> $metadata */ public function setMetadata(array $metadata): void { $this->metadata = $metadata; $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
