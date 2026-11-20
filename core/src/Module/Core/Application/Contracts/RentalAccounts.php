<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Domain\Entity\RentalAccount;
use App\Module\Core\Domain\Entity\RentalCredit;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\ValueObject\MinuteTariff;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RentalAccounts
{
    public function __construct(private EntityManagerInterface $em, private AuditLoggerInterface $audit, private WalletLedger $ledger)
    {
    }

    public function forCustomer(User $customer, string $currency): RentalAccount
    {
        return $this->em->wrapInTransaction(function () use ($customer, $currency): RentalAccount {
            $this->em->lock($customer, LockMode::PESSIMISTIC_WRITE);
            $account = $this->em->getRepository(RentalAccount::class)->findOneBy(['customer' => $customer, 'currency' => $currency]);
            if (null === $account) {
                $account = new RentalAccount($customer, $currency);
                $this->em->persist($account);
                $this->em->flush();
            }

            return $account;
        });
    }

    public function credit(RentalAccount $account, User $actor, int $cents, string $reference, string $reason): void
    {
        if (!$actor->isAdmin() || $cents < 1 || strlen($reference) < 8 || strlen($reference) > 120 || '' === trim($reason) || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('Admin, Betrag, eindeutige Referenz und Buchungsgrund sind erforderlich.');
        }
        $this->em->wrapInTransaction(function () use ($account, $actor, $cents, $reference, $reason): void {
            $this->em->refresh($account, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->em->getRepository(RentalCredit::class)->findOneBy(['reference' => $reference]);
            if (null !== $existing) {
                if ($existing->getAccount()->getId() !== $account->getId() || $existing->getAmountCents() !== $cents) {
                    throw new \DomainException('Diese Buchungsreferenz wurde bereits anders verwendet.');
                }

                return;
            }
            $account->credit($cents);
            $this->em->persist(new RentalCredit($account, $actor, $cents, $reference, $reason));
            $this->ledger->post($account, 'manual_credit', MinuteTariff::centsToUnits($cents), 'manual:'.$reference, $reason, $actor);
            $this->audit->log($actor, 'rental.wallet.credited', ['account_id' => $account->getId(), 'amount_cents' => $cents, 'reference' => $reference]);
        });
    }

    public function adjust(RentalAccount $account, User $actor, int $cents, string $reference, string $reason, bool $refund = false): void
    {
        if (!$actor->isAdmin() || 0 === $cents || abs($cents) > 2_147_483_647 || strlen($reference) < 8 || strlen($reference) > 100 || '' === trim($reason) || mb_strlen($reason) > 500 || ($refund && $cents > 0)) {
            throw new \InvalidArgumentException('Betrag, Buchungsreferenz und Begründung prüfen.');
        }
        $this->em->wrapInTransaction(function () use ($account, $actor, $cents, $reference, $reason, $refund): void {
            $this->em->refresh($account, LockMode::PESSIMISTIC_WRITE);
            $units = MinuteTariff::centsToUnits(abs($cents)) * ($cents < 0 ? -1 : 1);
            if ($this->ledger->post($account, $refund ? 'refund' : 'correction', $units, 'adjust:'.$reference, $reason, $actor)) {
                if ($cents > 0) {
                    $account->credit($cents);
                } else {
                    $account->debit(-$units);
                }
                $this->audit->log($actor, 'wallet.adjusted', ['account_id' => $account->getId(), 'amount_cents' => $cents, 'reference' => $reference, 'refund' => $refund]);
            }
        });
    }

    public function availableCreditUnits(RentalAccount $account): int
    {
        $debt = (int) $this->em->getConnection()->fetchOne("SELECT COALESCE(SUM(CASE WHEN i.amount_due_cents > COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.invoice_id=i.id AND p.status='succeeded'),0) THEN i.amount_due_cents - COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.invoice_id=i.id AND p.status='succeeded'),0) ELSE 0 END),0) FROM invoices i WHERE i.customer_id=? AND i.currency=? AND i.status IN ('open','past_due')", [$account->getCustomer()->getId(), $account->getCurrency()]);
        if ($debt >= $account->getCreditLimitCents()) {
            return 0;
        }
        $pending = (int) $this->em->getConnection()->fetchOne("SELECT COALESCE(SUM(pending_units),0) FROM shop_contracts WHERE account_id=? AND billing_mode='postpaid'", [$account->getId()]);

        return max(0, MinuteTariff::centsToUnits($account->getCreditLimitCents()) - $debt * MinuteTariff::UNITS_PER_CENT - $pending - $account->getCarry('postpaid') - $account->getPostpaidReservedUnits());
    }

    public function setLimit(RentalAccount $account, User $actor, int $cents): void
    {
        if (!$actor->isAdmin()) {
            throw new \DomainException('Adminrechte erforderlich.');
        }
        MinuteTariff::centsToUnits($cents);
        $this->em->wrapInTransaction(function () use ($account, $actor, $cents): void {
            $this->em->refresh($account, LockMode::PESSIMISTIC_WRITE);
            $old = $account->getCreditLimitCents();
            $account->setCreditLimitCents($cents);
            $this->audit->log($actor, 'rental.credit_limit.changed', ['account_id' => $account->getId(), 'old_cents' => $old, 'new_cents' => $cents]);
        });
    }
}
