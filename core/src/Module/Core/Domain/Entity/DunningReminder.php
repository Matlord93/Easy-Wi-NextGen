<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Enum\DunningStatus;
use App\Repository\DunningReminderRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DunningReminderRepository::class)]
#[ORM\Table(name: 'dunning_reminders')]
class DunningReminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'reminders')]
    #[ORM\JoinColumn(nullable: false)]
    private Invoice $invoice;

    #[ORM\Column]
    private int $level;

    #[ORM\Column]
    private int $feeCents;

    #[ORM\Column]
    private int $graceDays;

    #[ORM\Column(enumType: DunningStatus::class)]
    private DunningStatus $status;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Invoice $invoice, int $level, int $feeCents, int $graceDays)
    {
        $this->invoice = $invoice;
        $this->level = $level;
        $this->feeCents = $feeCents;
        $this->graceDays = $graceDays;
        $this->status = DunningStatus::Pending;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getFeeCents(): int
    {
        return $this->feeCents;
    }

    public function getGraceDays(): int
    {
        return $this->graceDays;
    }

    public function getStatus(): DunningStatus
    {
        return $this->status;
    }

    public function markSent(): void
    {
        $this->status = DunningStatus::Sent;
        $this->sentAt = new \DateTimeImmutable();
    }

    public function markResolved(): void
    {
        $this->status = DunningStatus::Resolved;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
