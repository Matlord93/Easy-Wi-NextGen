<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LogIndexRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LogIndexRepository::class)]
#[ORM\Table(name: 'log_indices')]
#[ORM\UniqueConstraint(name: 'log_indices_identity', columns: ['agent_id', 'source', 'scope_type', 'scope_id', 'log_name'])]
class LogIndex
{
    public const SOURCE_JOB = 'job';
    public const SOURCE_NGINX = 'nginx';
    public const SOURCE_MAIL = 'mail';

    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Agent $agent = null;

    #[ORM\Column(length: 20)]
    private string $source;

    #[ORM\Column(length: 40)]
    private string $scopeType;

    #[ORM\Column(length: 64)]
    private string $scopeId;

    #[ORM\Column(length: 80)]
    private string $logName;

    #[ORM\Column(length: 255)]
    private string $filePath;

    #[ORM\Column(type: 'bigint')]
    private int $byteOffset;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastIndexedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $source,
        string $scopeType,
        string $scopeId,
        string $logName,
        string $filePath,
        ?Agent $agent = null,
        int $byteOffset = 0,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->source = $source;
        $this->scopeType = $scopeType;
        $this->scopeId = $scopeId;
        $this->logName = $logName;
        $this->filePath = $filePath;
        $this->agent = $agent;
        $this->byteOffset = $byteOffset;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAgent(): ?Agent
    {
        return $this->agent;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getScopeType(): string
    {
        return $this->scopeType;
    }

    public function getScopeId(): string
    {
        return $this->scopeId;
    }

    public function getLogName(): string
    {
        return $this->logName;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): void
    {
        $this->filePath = $filePath;
        $this->touch();
    }

    public function getByteOffset(): int
    {
        return $this->byteOffset;
    }

    public function getLastIndexedAt(): ?\DateTimeImmutable
    {
        return $this->lastIndexedAt;
    }

    public function markIndexed(int $byteOffset, ?\DateTimeImmutable $indexedAt = null): void
    {
        $this->byteOffset = $byteOffset;
        $this->lastIndexedAt = $indexedAt ?? new \DateTimeImmutable();
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
