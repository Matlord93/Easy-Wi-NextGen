<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RetentionPolicyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RetentionPolicyRepository::class)]
#[ORM\Table(name: 'retention_policies')]
class RetentionPolicy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $ticketRetentionDays;

    #[ORM\Column]
    private int $logRetentionDays;

    #[ORM\Column]
    private int $sessionRetentionDays;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $ticketRetentionDays, int $logRetentionDays, int $sessionRetentionDays)
    {
        $this->ticketRetentionDays = $ticketRetentionDays;
        $this->logRetentionDays = $logRetentionDays;
        $this->sessionRetentionDays = $sessionRetentionDays;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicketRetentionDays(): int
    {
        return $this->ticketRetentionDays;
    }

    public function setTicketRetentionDays(int $ticketRetentionDays): void
    {
        $this->ticketRetentionDays = $ticketRetentionDays;
        $this->touch();
    }

    public function getLogRetentionDays(): int
    {
        return $this->logRetentionDays;
    }

    public function setLogRetentionDays(int $logRetentionDays): void
    {
        $this->logRetentionDays = $logRetentionDays;
        $this->touch();
    }

    public function getSessionRetentionDays(): int
    {
        return $this->sessionRetentionDays;
    }

    public function setSessionRetentionDays(int $sessionRetentionDays): void
    {
        $this->sessionRetentionDays = $sessionRetentionDays;
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
