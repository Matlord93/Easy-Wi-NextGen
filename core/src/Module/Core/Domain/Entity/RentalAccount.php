<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\ValueObject\MinuteTariff;
use Doctrine\ORM\Mapping as ORM;

/** @psalm-api Doctrine and Twig access this financial account. */
#[ORM\Entity, ORM\Table(name: 'rental_accounts')]
#[ORM\UniqueConstraint(name: 'uniq_rental_account_owner_currency', columns: ['customer_id', 'currency'])]
class RentalAccount
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\Column(type: 'bigint')] private int $balanceUnits = 0;
    #[ORM\Column(type: 'bigint')] private int $reservedUnits = 0;
    #[ORM\Column(type: 'bigint')] private int $postpaidCarryUnits = 0;
    #[ORM\Column(type: 'bigint')] private int $walletCarryUnits = 0;
    #[ORM\Column] private int $creditLimitCents = 10_000;
    #[ORM\Column] private \DateTimeImmutable $nextInvoiceAt;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private User $customer,
        #[ORM\Column(length: 3)] private string $currency,
    ) {
        $this->nextInvoiceAt = (new \DateTimeImmutable('first day of next month'))->setTime(0, 0);
    }

    #[ORM\Column(type: 'bigint', options: ['default' => 0])] private int $postpaidReservedUnits = 0;

    public function getPostpaidReservedUnits(): int
    {
        return $this->postpaidReservedUnits;
    }

    public function reserveCredit(int $units, int $available): void
    {
        if ($units < 0 || $units > $available || $units > PHP_INT_MAX - $this->postpaidReservedUnits) {
            throw new \DomainException('Rechnungslimit reicht für diesen Vertrag nicht aus.');
        } $this->postpaidReservedUnits += $units;
    }

    public function releaseCredit(int $units): void
    {
        if ($units < 0 || $units > $this->postpaidReservedUnits) {
            throw new \LogicException('Invalid credit reservation.');
        } $this->postpaidReservedUnits -= $units;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBalanceUnits(): int
    {
        return $this->balanceUnits;
    }

    public function getBalanceCents(): int
    {
        return intdiv($this->balanceUnits, MinuteTariff::UNITS_PER_CENT);
    }

    public function getAvailableUnits(): int
    {
        return $this->balanceUnits - $this->reservedUnits;
    }

    public function reserve(int $units): void
    {
        if ($units < 0 || $units > $this->getAvailableUnits()) {
            throw new \DomainException('Guthaben reicht für diesen Vertrag nicht aus.');
        } $this->reservedUnits += $units;
    }

    public function release(int $units): void
    {
        if ($units < 0 || $units > $this->reservedUnits) {
            throw new \LogicException('Invalid wallet reservation.');
        } $this->reservedUnits -= $units;
    }

    public function debit(int $units): void
    {
        if ($units < 0 || $units > $this->getAvailableUnits()) {
            throw new \DomainException('Insufficient wallet balance.');
        } $this->balanceUnits -= $units;
    }

    public function credit(int $cents): void
    {
        $units = MinuteTariff::centsToUnits($cents);
        if ($this->balanceUnits > PHP_INT_MAX - $units) {
            throw new \DomainException('Wallet balance overflow.');
        } $this->balanceUnits += $units;
    }

    public function getCreditLimitCents(): int
    {
        return $this->creditLimitCents;
    }

    public function setCreditLimitCents(int $cents): void
    {
        MinuteTariff::centsToUnits($cents);
        $this->creditLimitCents = $cents;
    }

    public function getCarry(string $mode): int
    {
        return 'wallet' === $mode ? $this->walletCarryUnits : $this->postpaidCarryUnits;
    }

    public function setCarry(string $mode, int $units): void
    {
        if ($units < 0 || $units >= MinuteTariff::UNITS_PER_CENT) {
            throw new \LogicException('Invalid invoice rounding carry.');
        } if ('wallet' === $mode) {
            $this->walletCarryUnits = $units;
        } else {
            $this->postpaidCarryUnits = $units;
        }
    }

    public function getNextInvoiceAt(): \DateTimeImmutable
    {
        return $this->nextInvoiceAt;
    }

    public function invoiced(\DateTimeImmutable $now): void
    {
        $this->nextInvoiceAt = $now->modify('first day of next month')->setTime(0, 0);
    }
}
