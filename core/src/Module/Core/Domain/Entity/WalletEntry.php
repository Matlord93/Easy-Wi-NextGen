<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/** @psalm-api Immutable money movements; ongoing usage is separately visible until settlement. */
#[ORM\Entity, ORM\Table(name: 'wallet_entries')]
#[ORM\UniqueConstraint(name: 'uniq_wallet_entry_reference', columns: ['reference'])]
#[ORM\Index(name: 'idx_wallet_entry_history', columns: ['account_id', 'id'])]
class WalletEntry
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private RentalAccount $account,
        #[ORM\Column(length: 24)] private string $kind,
        #[ORM\Column(type: 'bigint')] private int $units,
        #[ORM\Column(length: 160)] private string $reference,
        #[ORM\Column(length: 500)] private string $description,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true)] private ?User $actor = null,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true)] private ?ShopContract $contract = null,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true)] private ?Invoice $invoice = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): RentalAccount
    {
        return $this->account;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getUnits(): int
    {
        return $this->units;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getContract(): ?ShopContract
    {
        return $this->contract;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
