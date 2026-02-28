<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\SecurityEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SecurityEventRepository::class)]
#[ORM\Table(name: 'security_events')]
#[ORM\Index(columns: ['occurred_at'], name: 'idx_security_events_occurred')]
#[ORM\Index(columns: ['direction'], name: 'idx_security_events_direction')]
#[ORM\Index(columns: ['source'], name: 'idx_security_events_source')]
#[ORM\Index(columns: ['ip'], name: 'idx_security_events_ip')]
#[ORM\Index(columns: ['rule'], name: 'idx_security_events_rule')]
#[ORM\Index(columns: ['dedup_key'], name: 'idx_security_events_dedup')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_security_events_expires')]
class SecurityEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(name: 'node_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Agent $node;

    #[ORM\Column(length: 16)]
    private string $direction;

    #[ORM\Column(length: 32)]
    private string $source;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $rule = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $count = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'dedup_key', length: 64)]
    private string $dedupKey;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    public function __construct(
        Agent $node,
        string $direction,
        string $source,
        ?string $reason,
        ?string $ip,
        ?string $rule,
        ?int $count,
        \DateTimeImmutable $occurredAt,
        ?string $dedupKey = null,
        ?\DateTimeImmutable $expiresAt = null,
    ) {
        $this->node = $node;
        $this->direction = $direction;
        $this->source = $source;
        $this->reason = $reason;
        $this->ip = $ip;
        $this->rule = $rule;
        $this->count = $count;
        $this->occurredAt = $occurredAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->dedupKey = $dedupKey ?? hash('sha256', implode('|', [
            $node->getId(),
            $direction,
            $source,
            $ip ?? '',
            $rule ?? '',
            $occurredAt->format(DATE_RFC3339),
        ]));
        $this->expiresAt = $expiresAt ?? $this->createdAt->modify('+7 days');
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getNode(): Agent
    {
        return $this->node;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getRule(): ?string
    {
        return $this->rule;
    }

    public function getCount(): ?int
    {
        return $this->count;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDedupKey(): string
    {
        return $this->dedupKey;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
