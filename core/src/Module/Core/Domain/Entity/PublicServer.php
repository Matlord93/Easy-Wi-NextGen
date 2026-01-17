<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\PublicServerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PublicServerRepository::class)]
#[ORM\Table(name: 'public_servers')]
#[ORM\Index(name: 'idx_public_servers_site_id', columns: ['site_id'])]
#[ORM\Index(name: 'idx_public_servers_visibility', columns: ['visible_public', 'visible_logged_in'])]
#[ORM\Index(name: 'idx_public_servers_game_key', columns: ['game_key'])]
class PublicServer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $siteId;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 80)]
    private string $category;

    #[ORM\Column(length: 120)]
    private string $gameKey;

    #[ORM\Column(length: 64)]
    private string $ip;

    #[ORM\Column]
    private int $port;

    #[ORM\Column(length: 40)]
    private string $queryType;

    #[ORM\Column(nullable: true)]
    private ?int $queryPort = null;

    #[ORM\Column]
    private bool $visiblePublic = false;

    #[ORM\Column]
    private bool $visibleLoggedIn = false;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notesInternal = null;

    #[ORM\Column(type: 'json')]
    private array $statusCache = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nextCheckAt = null;

    #[ORM\Column]
    private int $checkIntervalSeconds;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    public function __construct(
        int $siteId,
        string $name,
        string $category,
        string $gameKey,
        string $ip,
        int $port,
        string $queryType,
        int $checkIntervalSeconds,
        User $createdBy,
        ?int $queryPort = null,
        bool $visiblePublic = false,
        bool $visibleLoggedIn = false,
        int $sortOrder = 0,
        ?string $notesInternal = null,
        array $statusCache = [],
        ?\DateTimeImmutable $lastCheckedAt = null,
        ?\DateTimeImmutable $nextCheckAt = null,
    ) {
        $this->siteId = $siteId;
        $this->name = $name;
        $this->category = $category;
        $this->gameKey = $gameKey;
        $this->ip = $ip;
        $this->port = $port;
        $this->queryType = $queryType;
        $this->queryPort = $queryPort;
        $this->visiblePublic = $visiblePublic;
        $this->visibleLoggedIn = $visibleLoggedIn;
        $this->sortOrder = $sortOrder;
        $this->notesInternal = $notesInternal;
        $this->statusCache = $statusCache;
        $this->lastCheckedAt = $lastCheckedAt;
        $this->nextCheckAt = $nextCheckAt;
        $this->checkIntervalSeconds = $checkIntervalSeconds;
        $this->createdBy = $createdBy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSiteId(): int
    {
        return $this->siteId;
    }

    public function setSiteId(int $siteId): void
    {
        $this->siteId = $siteId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): void
    {
        $this->category = $category;
    }

    public function getGameKey(): string
    {
        return $this->gameKey;
    }

    public function setGameKey(string $gameKey): void
    {
        $this->gameKey = $gameKey;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function setIp(string $ip): void
    {
        $this->ip = $ip;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    public function getQueryType(): string
    {
        return $this->queryType;
    }

    public function setQueryType(string $queryType): void
    {
        $this->queryType = $queryType;
    }

    public function getQueryPort(): ?int
    {
        return $this->queryPort;
    }

    public function setQueryPort(?int $queryPort): void
    {
        $this->queryPort = $queryPort;
    }

    public function isVisiblePublic(): bool
    {
        return $this->visiblePublic;
    }

    public function setVisiblePublic(bool $visiblePublic): void
    {
        $this->visiblePublic = $visiblePublic;
    }

    public function isVisibleLoggedIn(): bool
    {
        return $this->visibleLoggedIn;
    }

    public function setVisibleLoggedIn(bool $visibleLoggedIn): void
    {
        $this->visibleLoggedIn = $visibleLoggedIn;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function getNotesInternal(): ?string
    {
        return $this->notesInternal;
    }

    public function setNotesInternal(?string $notesInternal): void
    {
        $this->notesInternal = $notesInternal;
    }

    public function getStatusCache(): array
    {
        return $this->statusCache;
    }

    public function setStatusCache(array $statusCache): void
    {
        $this->statusCache = $statusCache;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function setLastCheckedAt(?\DateTimeImmutable $lastCheckedAt): void
    {
        $this->lastCheckedAt = $lastCheckedAt;
    }

    public function getNextCheckAt(): ?\DateTimeImmutable
    {
        return $this->nextCheckAt;
    }

    public function setNextCheckAt(?\DateTimeImmutable $nextCheckAt): void
    {
        $this->nextCheckAt = $nextCheckAt;
    }

    public function getCheckIntervalSeconds(): int
    {
        return $this->checkIntervalSeconds;
    }

    public function setCheckIntervalSeconds(int $checkIntervalSeconds): void
    {
        $this->checkIntervalSeconds = $checkIntervalSeconds;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): void
    {
        $this->createdBy = $createdBy;
    }
}
