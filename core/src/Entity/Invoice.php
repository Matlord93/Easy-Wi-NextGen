<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InvoiceStatus;
use App\Repository\InvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoices')]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\Column(length: 40)]
    private string $number;

    #[ORM\Column(enumType: InvoiceStatus::class)]
    private InvoiceStatus $status;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column]
    private int $amountTotalCents;

    #[ORM\Column]
    private int $amountDueCents;

    #[ORM\Column]
    private \DateTimeImmutable $dueDate;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, Payment>
     */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: Payment::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $payments;

    /**
     * @var Collection<int, DunningReminder>
     */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: DunningReminder::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $reminders;

    /**
     * @var Collection<int, CreditNote>
     */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: CreditNote::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $creditNotes;

    public function __construct(User $customer, string $number, int $amountTotalCents, string $currency, \DateTimeImmutable $dueDate)
    {
        $this->customer = $customer;
        $this->number = $number;
        $this->amountTotalCents = $amountTotalCents;
        $this->amountDueCents = $amountTotalCents;
        $this->currency = $currency;
        $this->dueDate = $dueDate;
        $this->status = InvoiceStatus::Open;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->payments = new ArrayCollection();
        $this->reminders = new ArrayCollection();
        $this->creditNotes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getStatus(): InvoiceStatus
    {
        return $this->status;
    }

    public function setStatus(InvoiceStatus $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getAmountTotalCents(): int
    {
        return $this->amountTotalCents;
    }

    public function getAmountDueCents(): int
    {
        return $this->amountDueCents;
    }

    public function addFee(int $feeCents): void
    {
        $this->amountDueCents += $feeCents;
        $this->touch();
    }

    public function applyCredit(int $creditCents): void
    {
        if ($creditCents <= 0) {
            return;
        }

        $this->amountDueCents = max(0, $this->amountDueCents - $creditCents);
        $this->touch();
    }

    public function getDueDate(): \DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function extendDueDate(int $graceDays): void
    {
        if ($graceDays <= 0) {
            return;
        }

        $this->dueDate = $this->dueDate->modify(sprintf('+%d days', $graceDays));
        $this->touch();
    }

    public function markPaid(\DateTimeImmutable $paidAt): void
    {
        $this->status = InvoiceStatus::Paid;
        $this->paidAt = $paidAt;
        $this->touch();
    }

    public function clearPaidAt(): void
    {
        $this->paidAt = null;
        $this->touch();
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, Payment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function addPayment(Payment $payment): void
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
        }
    }

    /**
     * @return Collection<int, DunningReminder>
     */
    public function getReminders(): Collection
    {
        return $this->reminders;
    }

    public function addReminder(DunningReminder $reminder): void
    {
        if (!$this->reminders->contains($reminder)) {
            $this->reminders->add($reminder);
        }
    }

    /**
     * @return Collection<int, CreditNote>
     */
    public function getCreditNotes(): Collection
    {
        return $this->creditNotes;
    }

    public function addCreditNote(CreditNote $creditNote): void
    {
        if (!$this->creditNotes->contains($creditNote)) {
            $this->creditNotes->add($creditNote);
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
