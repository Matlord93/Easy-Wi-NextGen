<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Enum\CreditNoteStatus;
use App\Repository\CreditNoteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CreditNoteRepository::class)]
#[ORM\Table(name: 'credit_notes')]
class CreditNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'creditNotes')]
    #[ORM\JoinColumn(nullable: false)]
    private Invoice $invoice;

    #[ORM\Column(length: 40)]
    private string $number;

    #[ORM\Column(enumType: CreditNoteStatus::class)]
    private CreditNoteStatus $status;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Invoice $invoice, string $number, int $amountCents, string $currency, ?string $reason = null)
    {
        $this->invoice = $invoice;
        $this->number = $number;
        $this->amountCents = $amountCents;
        $this->currency = $currency;
        $this->reason = $reason;
        $this->status = CreditNoteStatus::Draft;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getStatus(): CreditNoteStatus
    {
        return $this->status;
    }

    public function markIssued(?\DateTimeImmutable $issuedAt = null): void
    {
        $this->status = CreditNoteStatus::Issued;
        $this->issuedAt = $issuedAt ?? new \DateTimeImmutable();
        $this->touch();
    }

    public function markApplied(): void
    {
        $this->status = CreditNoteStatus::Applied;
        $this->touch();
    }

    public function markVoided(): void
    {
        $this->status = CreditNoteStatus::Voided;
        $this->touch();
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getIssuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
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
