<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Enum\MusicbotStreamAccessMode;
use App\Repository\MusicbotStreamSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotStreamSettingsRepository::class)]
#[ORM\Table(name: 'musicbot_stream_settings')]
#[ORM\UniqueConstraint(name: 'uniq_musicbot_stream_instance', columns: ['instance_id'])]
#[ORM\UniqueConstraint(name: 'uniq_musicbot_stream_slug', columns: ['public_slug'])]
#[ORM\Index(name: 'idx_musicbot_stream_customer', columns: ['customer_id'])]
class MusicbotStreamSettings
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

    #[ORM\Column(length: 64)]
    private string $publicSlug;

    #[ORM\Column(enumType: MusicbotStreamAccessMode::class, length: 10)]
    private MusicbotStreamAccessMode $accessMode = MusicbotStreamAccessMode::Private;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $streamTitle = null;

    #[ORM\Column]
    private int $bitrate = 128;

    #[ORM\Column(length: 10)]
    private string $format = 'mp3';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $currentMountPath = null;

    /** Hashed stream token — null means no token issued yet */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $streamTokenHash = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $customer, MusicbotInstance $instance, string $publicSlug)
    {
        $this->customer = $customer;
        $this->instance = $instance;
        $this->publicSlug = $publicSlug;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): User { return $this->customer; }
    public function getInstance(): MusicbotInstance { return $this->instance; }
    public function isEnabled(): bool { return $this->enabled; }
    public function getPublicSlug(): string { return $this->publicSlug; }
    public function getAccessMode(): MusicbotStreamAccessMode { return $this->accessMode; }
    public function getStreamTitle(): ?string { return $this->streamTitle; }
    public function getBitrate(): int { return $this->bitrate; }
    public function getFormat(): string { return $this->format; }
    public function getCurrentMountPath(): ?string { return $this->currentMountPath; }
    public function getStreamTokenHash(): ?string { return $this->streamTokenHash; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setEnabled(bool $enabled): void { $this->enabled = $enabled; $this->touch(); }
    public function setAccessMode(MusicbotStreamAccessMode $mode): void { $this->accessMode = $mode; $this->touch(); }
    public function setStreamTitle(?string $title): void { $this->streamTitle = $title !== null && trim($title) !== '' ? trim($title) : null; $this->touch(); }

    public function setBitrate(int $bitrate): void
    {
        $this->bitrate = max(32, min(320, $bitrate));
        $this->touch();
    }

    public function setFormat(string $format): void
    {
        $allowed = ['mp3', 'ogg', 'aac', 'opus'];
        $this->format = in_array(strtolower($format), $allowed, true) ? strtolower($format) : 'mp3';
        $this->touch();
    }

    public function setCurrentMountPath(?string $path): void { $this->currentMountPath = $path; $this->touch(); }
    public function setStreamTokenHash(?string $hash): void { $this->streamTokenHash = $hash; $this->touch(); }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
