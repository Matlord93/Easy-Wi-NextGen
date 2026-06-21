<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Musicbot\Domain\Enum\MusicbotConnectionStatus;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use App\Module\Musicbot\Domain\Enum\MusicbotTeamspeakProfile;
use App\Repository\MusicbotConnectionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotConnectionRepository::class)]
#[ORM\Table(name: 'musicbot_connections')]
#[ORM\Index(name: 'idx_musicbot_connections_instance', columns: ['musicbot_instance_id'])]
class MusicbotConnection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MusicbotInstance::class)]
    #[ORM\JoinColumn(name: 'musicbot_instance_id', nullable: false, onDelete: 'CASCADE')]
    private MusicbotInstance $musicbotInstance;

    #[ORM\Column(enumType: MusicbotPlatform::class)]
    private MusicbotPlatform $platform;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $connectionConfig = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $secretConfig = [];

    #[ORM\Column(enumType: MusicbotConnectionStatus::class)]
    private MusicbotConnectionStatus $status = MusicbotConnectionStatus::Disconnected;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastConnectedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    /** @param array<string, mixed> $connectionConfig @param array<string, mixed> $secretConfig */
    public function __construct(MusicbotInstance $musicbotInstance, MusicbotPlatform $platform, array $connectionConfig = [], array $secretConfig = [])
    {
        $this->musicbotInstance = $musicbotInstance;
        $this->platform = $platform;
        $this->connectionConfig = $connectionConfig;
        $this->secretConfig = $secretConfig;
    }

    public function getId(): ?int { return $this->id; }
    public function getMusicbotInstance(): MusicbotInstance { return $this->musicbotInstance; }
    public function getPlatform(): MusicbotPlatform { return $this->platform; }
    public function setPlatform(MusicbotPlatform $platform): void { $this->platform = $platform; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): void { $this->enabled = $enabled; }
    /** @return array<string, mixed> */ public function getConnectionConfig(): array { return $this->connectionConfig; }
    /** @param array<string, mixed> $connectionConfig */ public function setConnectionConfig(array $connectionConfig): void { $this->connectionConfig = $connectionConfig; }
    /** @return array<string, mixed> */ public function getSecretConfig(): array { return $this->secretConfig; }
    /** @param array<string, mixed> $secretConfig */ public function setSecretConfig(array $secretConfig): void { $this->secretConfig = $secretConfig; }
    public function getStatus(): MusicbotConnectionStatus { return $this->status; }
    public function setStatus(MusicbotConnectionStatus $status): void { $this->status = $status; }
    public function getLastConnectedAt(): ?\DateTimeImmutable { return $this->lastConnectedAt; }
    public function setLastConnectedAt(?\DateTimeImmutable $lastConnectedAt): void { $this->lastConnectedAt = $lastConnectedAt; }
    public function getLastError(): ?string { return $this->lastError; }
    public function setLastError(?string $lastError): void { $this->lastError = $lastError; }

    public function getTeamspeakProfile(): MusicbotTeamspeakProfile
    {
        if ($this->platform !== MusicbotPlatform::Teamspeak) {
            return MusicbotTeamspeakProfile::Ts3;
        }

        return MusicbotTeamspeakProfile::tryFrom((string) ($this->connectionConfig['profile'] ?? '')) ?? MusicbotTeamspeakProfile::Ts3;
    }

    public function setTeamspeakProfile(MusicbotTeamspeakProfile $profile): void
    {
        $this->connectionConfig['profile'] = $profile->value;
        $this->connectionConfig['backend'] = 'ts3_client_compatible';
    }

    public function getTeamspeakBackend(): ?string
    {
        if ($this->platform !== MusicbotPlatform::Teamspeak) {
            return null;
        }

        return (string) ($this->connectionConfig['backend'] ?? 'ts3_client_compatible');
    }
}
