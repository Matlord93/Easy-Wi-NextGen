<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Event\ResourceEventSource;
use App\Module\Core\Domain\Event\ResourceEventSourceTrait;
use App\Repository\WebspaceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebspaceRepository::class)]
#[ORM\Table(name: 'webspaces')]
class Webspace implements ResourceEventSource
{
    use ResourceEventSourceTrait;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_DELETED = 'deleted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Agent $node;

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(length: 255)]
    private string $docroot;

    #[ORM\Column(length: 255)]
    private string $domain;

    #[ORM\Column]
    private bool $ddosProtectionEnabled;

    #[ORM\Column(nullable: true)]
    private ?int $assignedPort = null;

    #[ORM\Column(length: 20)]
    private string $phpVersion;

    #[ORM\Column]
    private int $quota;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column]
    private int $diskLimitBytes;

    #[ORM\Column]
    private bool $ftpEnabled;

    #[ORM\Column]
    private bool $sftpEnabled;

    #[ORM\Column(length: 64)]
    private string $systemUsername;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $customer,
        Agent $node,
        string $path,
        string $docroot,
        string $domain,
        bool $ddosProtectionEnabled = true,
        ?int $assignedPort = null,
        string $phpVersion,
        int $quota,
        string $status = self::STATUS_ACTIVE,
        int $diskLimitBytes = 0,
        bool $ftpEnabled = false,
        bool $sftpEnabled = false,
        string $systemUsername = '',
    )
    {
        $this->customer = $customer;
        $this->node = $node;
        $this->path = $path;
        $this->docroot = $docroot;
        $this->domain = $domain;
        $this->ddosProtectionEnabled = $ddosProtectionEnabled;
        $this->assignedPort = $assignedPort;
        $this->phpVersion = $phpVersion;
        $this->quota = $quota;
        $this->status = $status;
        $this->diskLimitBytes = $diskLimitBytes;
        $this->ftpEnabled = $ftpEnabled;
        $this->sftpEnabled = $sftpEnabled;
        $this->systemUsername = $systemUsername;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getNode(): Agent
    {
        return $this->node;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getDocroot(): string
    {
        return $this->docroot;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function isDdosProtectionEnabled(): bool
    {
        return $this->ddosProtectionEnabled;
    }

    public function getAssignedPort(): ?int
    {
        return $this->assignedPort;
    }

    public function getPhpVersion(): string
    {
        return $this->phpVersion;
    }

    public function getQuota(): int
    {
        return $this->quota;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDiskLimitBytes(): int
    {
        return $this->diskLimitBytes;
    }

    public function isFtpEnabled(): bool
    {
        return $this->ftpEnabled;
    }

    public function isSftpEnabled(): bool
    {
        return $this->sftpEnabled;
    }

    public function getSystemUsername(): string
    {
        return $this->systemUsername;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
        $this->touch();
    }

    public function setDocroot(string $docroot): void
    {
        $this->docroot = $docroot;
        $this->touch();
    }

    public function setDomain(string $domain): void
    {
        $this->domain = $domain;
        $this->touch();
    }

    public function setDdosProtectionEnabled(bool $ddosProtectionEnabled): void
    {
        $this->ddosProtectionEnabled = $ddosProtectionEnabled;
        $this->touch();
    }

    public function setAssignedPort(?int $assignedPort): void
    {
        $this->assignedPort = $assignedPort;
        $this->touch();
    }

    public function setPhpVersion(string $phpVersion): void
    {
        $this->phpVersion = $phpVersion;
        $this->touch();
    }

    public function setQuota(int $quota): void
    {
        $this->quota = $quota;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function setDiskLimitBytes(int $diskLimitBytes): void
    {
        $this->diskLimitBytes = $diskLimitBytes;
        $this->touch();
    }

    public function setFtpEnabled(bool $ftpEnabled): void
    {
        $this->ftpEnabled = $ftpEnabled;
        $this->touch();
    }

    public function setSftpEnabled(bool $sftpEnabled): void
    {
        $this->sftpEnabled = $sftpEnabled;
        $this->touch();
    }

    public function setSystemUsername(string $systemUsername): void
    {
        $this->systemUsername = $systemUsername;
        $this->touch();
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): void
    {
        $this->deletedAt = $deletedAt;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
