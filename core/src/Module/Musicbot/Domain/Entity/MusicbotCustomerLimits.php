<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Repository\MusicbotCustomerLimitsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotCustomerLimitsRepository::class)]
#[ORM\Table(name: 'musicbot_customer_limits')]
#[ORM\UniqueConstraint(name: 'uniq_musicbot_customer_limits_customer', columns: ['customer_id'])]
class MusicbotCustomerLimits
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\Column(nullable: true)]
    private ?int $maxMusicbots = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxTracks = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxStorageMb = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxPlaylists = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxPlaylistItems = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxPlugins = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxQueueItems = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxConnections = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxUploadSizeMb = null;

    #[ORM\Column(nullable: true)]
    private ?bool $allowTeamspeak = null;

    #[ORM\Column(nullable: true)]
    private ?bool $allowDiscord = null;

    #[ORM\Column(nullable: true)]
    private ?bool $allowStream = null;

    #[ORM\Column(nullable: true)]
    private ?bool $allowApi = null;

    #[ORM\Column(nullable: true)]
    private ?bool $allowTeamspeak6Profile = null;

    #[ORM\Column(nullable: true)]
    private ?bool $allowWebradio = null;

    #[ORM\Column(nullable: true)]
    private ?bool $allowPlugins = null;

    #[ORM\Column(nullable: true)]
    private ?bool $allowWorkflows = null;

    #[ORM\Column(nullable: true)]
    private ?bool $allowScheduler = null;

    /** @var string[]|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $grantedPermissions = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $customer)
    {
        $this->customer = $customer;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): User { return $this->customer; }

    public function getMaxMusicbots(): ?int { return $this->maxMusicbots; }
    public function setMaxMusicbots(?int $v): void { $this->maxMusicbots = $this->normalizeLimit($v); $this->touch(); }

    public function getMaxTracks(): ?int { return $this->maxTracks; }
    public function setMaxTracks(?int $v): void { $this->maxTracks = $this->normalizeLimit($v); $this->touch(); }

    public function getMaxStorageMb(): ?int { return $this->maxStorageMb; }
    public function setMaxStorageMb(?int $v): void { $this->maxStorageMb = $this->normalizeLimit($v); $this->touch(); }

    public function getMaxPlaylists(): ?int { return $this->maxPlaylists; }
    public function setMaxPlaylists(?int $v): void { $this->maxPlaylists = $this->normalizeLimit($v); $this->touch(); }

    public function getMaxPlaylistItems(): ?int { return $this->maxPlaylistItems; }
    public function setMaxPlaylistItems(?int $v): void { $this->maxPlaylistItems = $this->normalizeLimit($v); $this->touch(); }

    public function getMaxPlugins(): ?int { return $this->maxPlugins; }
    public function setMaxPlugins(?int $v): void { $this->maxPlugins = $this->normalizeLimit($v); $this->touch(); }

    public function getMaxQueueItems(): ?int { return $this->maxQueueItems; }
    public function setMaxQueueItems(?int $v): void { $this->maxQueueItems = $this->normalizeLimit($v); $this->touch(); }

    public function getMaxConnections(): ?int { return $this->maxConnections; }
    public function setMaxConnections(?int $v): void { $this->maxConnections = $this->normalizeLimit($v); $this->touch(); }

    public function getMaxUploadSizeMb(): ?int { return $this->maxUploadSizeMb; }
    public function setMaxUploadSizeMb(?int $v): void { $this->maxUploadSizeMb = $this->normalizeLimit($v); $this->touch(); }

    public function getAllowTeamspeak(): ?bool { return $this->allowTeamspeak; }
    public function setAllowTeamspeak(?bool $v): void { $this->allowTeamspeak = $v; $this->touch(); }

    public function getAllowDiscord(): ?bool { return $this->allowDiscord; }
    public function setAllowDiscord(?bool $v): void { $this->allowDiscord = $v; $this->touch(); }

    public function getAllowStream(): ?bool { return $this->allowStream; }
    public function setAllowStream(?bool $v): void { $this->allowStream = $v; $this->touch(); }

    public function getAllowApi(): ?bool { return $this->allowApi; }
    public function setAllowApi(?bool $v): void { $this->allowApi = $v; $this->touch(); }

    public function getAllowTeamspeak6Profile(): ?bool { return $this->allowTeamspeak6Profile; }
    public function setAllowTeamspeak6Profile(?bool $v): void { $this->allowTeamspeak6Profile = $v; $this->touch(); }

    public function getAllowWebradio(): ?bool { return $this->allowWebradio; }
    public function setAllowWebradio(?bool $v): void { $this->allowWebradio = $v; $this->touch(); }

    public function getAllowPlugins(): ?bool { return $this->allowPlugins; }
    public function setAllowPlugins(?bool $v): void { $this->allowPlugins = $v; $this->touch(); }

    public function getAllowWorkflows(): ?bool { return $this->allowWorkflows; }
    public function setAllowWorkflows(?bool $v): void { $this->allowWorkflows = $v; $this->touch(); }

    public function getAllowScheduler(): ?bool { return $this->allowScheduler; }
    public function setAllowScheduler(?bool $v): void { $this->allowScheduler = $v; $this->touch(); }

    /** @return string[]|null */
    public function getGrantedPermissions(): ?array { return $this->grantedPermissions; }

    /** @param string[]|null $permissions */
    public function setGrantedPermissions(?array $permissions): void { $this->grantedPermissions = $permissions; $this->touch(); }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function normalizeLimit(?int $v): ?int
    {
        if ($v === null) {
            return null;
        }

        return $v < 0 ? -1 : $v;
    }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
