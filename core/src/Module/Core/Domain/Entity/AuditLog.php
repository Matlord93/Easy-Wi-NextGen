<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_logs')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $actor = null;

    #[ORM\Column(length: 120)]
    private string $action;

    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $hashPrev = null;

    #[ORM\Column(length: 64)]
    private string $hashCurrent;

    public function __construct(?User $actor, string $action, array $payload)
    {
        $this->actor = $actor;
        $this->action = $action;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setHashPrev(?string $hashPrev): void
    {
        $this->hashPrev = $hashPrev;
    }

    public function getHashPrev(): ?string
    {
        return $this->hashPrev;
    }

    public function setHashCurrent(string $hashCurrent): void
    {
        $this->hashCurrent = $hashCurrent;
    }

    public function getHashCurrent(): string
    {
        return $this->hashCurrent;
    }
}
