<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/** @psalm-api A funding request/receipt, not a second taxable usage invoice. */
#[ORM\Entity, ORM\Table(name: 'wallet_topups')]
#[ORM\UniqueConstraint(name: 'uniq_wallet_topup_request', columns: ['request_key'])]
#[ORM\Index(name: 'idx_wallet_topup_account', columns: ['account_id', 'created_at'])]
class WalletTopup
{
    #[ORM\Id, ORM\Column(length: 32)] private string $id;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $paidAt = null;
    #[ORM\Column] private int $creditedCents = 0;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private RentalAccount $account,
        #[ORM\Column] private int $amountCents,
        #[ORM\Column(length: 64)] private string $requestKey,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return 'WLT-'.strtoupper($this->id);
    }

    public function getAccount(): RentalAccount
    {
        return $this->account;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function getCurrency(): string
    {
        return $this->account->getCurrency();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getCreditedCents(): int
    {
        return $this->creditedCents;
    }

    public function credited(\DateTimeImmutable $at): void
    {
        if ($this->creditedCents > 2_147_483_647 - $this->amountCents) {
            throw new \DomainException('Funding receipt limit reached.');
        }
        $this->creditedCents += $this->amountCents;
        $this->paidAt ??= $at;
    }
}
