<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/** @psalm-api Immutable audited wallet credit, never writable by a customer. */
#[ORM\Entity, ORM\Table(name: 'rental_credits')]
#[ORM\UniqueConstraint(name: 'uniq_rental_credit_reference', columns: ['reference'])]
class RentalCredit
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private RentalAccount $account,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private User $actor,
        #[ORM\Column] private int $amountCents,
        #[ORM\Column(length: 120)] private string $reference,
        #[ORM\Column(length: 500)] private string $reason,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function getAccount(): RentalAccount
    {
        return $this->account;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
