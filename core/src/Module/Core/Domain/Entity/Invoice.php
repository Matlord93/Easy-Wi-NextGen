<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Enum\InvoiceStatus;
use App\Module\Core\Domain\Enum\PaymentStatus;
use App\Repository\InvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine hydrates and proxies this entity; Twig also calls its public accessors.
 *
 * @psalm-api
 */
#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoices')]
#[ORM\UniqueConstraint(name: 'uniq_invoices_number', columns: ['number'])]
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

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $billingDetails = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cancellationReason = null;

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
        if ($amountTotalCents <= 0 || $amountTotalCents > 2_147_483_647 || !preg_match('/^[A-Z]{3}$/D', $currency) || trim($number) === '' || mb_strlen($number) > 40) {
            throw new \InvalidArgumentException('billing.invalid_invoice');
        }
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
        if ($this->status === InvoiceStatus::Cancelled && $status !== InvoiceStatus::Cancelled) {
            throw new \DomainException('billing.cancelled_invoice');
        }
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
        if ($feeCents < 0 || $this->amountDueCents + $feeCents > 2_147_483_647 || $this->status === InvoiceStatus::Cancelled) {
            throw new \DomainException('billing.invalid_amount');
        }
        $this->amountDueCents += $feeCents;
        $this->touch();
    }

    public function applyCredit(int $creditCents): void
    {
        if ($creditCents <= 0 || $creditCents > $this->amountDueCents || $this->status === InvoiceStatus::Cancelled) {
            throw new \DomainException('billing.invalid_credit');
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
        if ($this->status === InvoiceStatus::Cancelled) {
            throw new \DomainException('billing.cancelled_invoice');
        }
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

    public function getBillingDetails(): array
    {
        return $this->billingDetails ?? [];
    }

    public function setBillingDetails(array $details): void
    {
        if ($this->billingDetails !== null) {
            throw new \DomainException('billing.immutable_details');
        }
        $this->billingDetails = $details;
    }

    public function getPaidAmountCents(): int
    {
        $paid = 0;
        foreach ($this->payments as $payment) {
            if ($payment->getStatus() === PaymentStatus::Succeeded && $payment->getCurrency() === $this->currency) {
                $paid += $payment->getAmountCents();
            }
        }

        return $paid;
    }

    public function getOutstandingCents(): int
    {
        return $this->status === InvoiceStatus::Cancelled ? 0 : max(0, $this->amountDueCents - $this->getPaidAmountCents());
    }

    public function cancel(string $reason, ?\DateTimeImmutable $now = null): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 255 || $this->status === InvoiceStatus::Cancelled) {
            throw new \DomainException('billing.invalid_cancellation');
        }
        $this->status = InvoiceStatus::Cancelled;
        $this->cancellationReason = trim($reason);
        $this->cancelledAt = $now ?? new \DateTimeImmutable();
        $this->touch();
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }
    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
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
