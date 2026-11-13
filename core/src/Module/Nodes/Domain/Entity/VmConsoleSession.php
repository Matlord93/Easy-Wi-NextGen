<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity, ORM\Table(name: 'vm_console_sessions')]
#[ORM\UniqueConstraint(name: 'uniq_vm_console_ticket', columns: ['ticket_hash'])]
#[ORM\Index(name: 'idx_vm_console_expiry', columns: ['expires_at'])]
#[ORM\Index(name: 'idx_vm_console_customer_created', columns: ['customer_id', 'created_at'])]
class VmConsoleSession
{
    #[ORM\Id, ORM\Column(length: 36)] private string $id;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $connectBefore;
    #[ORM\Column] private \DateTimeImmutable $expiresAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $claimedAt = null;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private User $customer,
        #[ORM\Column(length: 10)] private string $provider,
        #[ORM\Column] private int $assignmentId,
        #[ORM\Column(length: 64)] private string $sessionHash,
        #[ORM\Column(length: 64)] private string $ticketHash,
    ) {
        $this->id = Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
        $this->connectBefore = $this->createdAt->modify('+45 seconds');
        $this->expiresAt = $this->createdAt->modify('+10 minutes');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getAssignmentId(): int
    {
        return $this->assignmentId;
    }

    public function getSessionHash(): string
    {
        return $this->sessionHash;
    }

    public function getConnectBefore(): \DateTimeImmutable
    {
        return $this->connectBefore;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }
}
