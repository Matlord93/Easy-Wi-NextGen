<?php

declare(strict_types=1);

namespace App\Module\Unifi\Domain\Entity;

use App\Module\Unifi\Infrastructure\Repository\UnifiAuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UnifiAuditLogRepository::class)]
#[ORM\Table(name: 'unifi_audit_log')]
class UnifiAuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $action;

    #[ORM\Column(length: 40)]
    private string $status;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $requestId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $context = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>|null $context
     */
    public function __construct(string $action, string $status, ?string $requestId = null, ?string $error = null, ?array $context = null)
    {
        $this->action = $action;
        $this->status = $status;
        $this->requestId = $requestId;
        $this->error = $error;
        $this->context = $context;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return is_array($this->context) ? $this->context : [];
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
