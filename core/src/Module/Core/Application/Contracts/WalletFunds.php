<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Domain\Entity\PaymentCheckout;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\WalletTopup;
use App\Module\Core\Domain\ValueObject\MinuteTariff;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WalletFunds
{
    public function __construct(private EntityManagerInterface $em, private RentalAccounts $accounts, private WalletLedger $ledger, private AuditLoggerInterface $audit, private PaymentSettings $settings, private RentalNotices $notices)
    {
    }

    public function request(User $customer, int $cents, string $nonce, ?string $currency = null): WalletTopup
    {
        if (!$customer->isCustomer() || $cents < 100 || $cents > 1_000_000 || !preg_match('/^[a-f0-9]{32}$/D', $nonce)) {
            throw new \InvalidArgumentException('Aufladung zwischen 1,00 und 10.000,00 mit gültiger Bestellreferenz erforderlich.');
        }
        $currentCurrency = $this->settings->publicSettings()['currency'];
        if (null !== $currency && $currency !== $currentCurrency) {
            throw new \InvalidArgumentException('Die Aufladewährung hat sich geändert. Bitte die Aufladung erneut prüfen.');
        }
        $account = $this->accounts->forCustomer($customer, $currentCurrency);

        return $this->em->wrapInTransaction(function () use ($account, $cents, $nonce): WalletTopup {
            $this->em->refresh($account, LockMode::PESSIMISTIC_WRITE);
            $key = hash('sha256', \App\Infrastructure\Doctrine\EntityIdentifier::require($account->getId()).':'.$nonce);
            $existing = $this->em->getRepository(WalletTopup::class)->findOneBy(['requestKey' => $key]);
            if (null !== $existing) {
                if ($existing->getAmountCents() !== $cents) {
                    throw new \DomainException('Diese Aufladungsreferenz wurde bereits mit einem anderen Betrag verwendet.');
                }

                return $existing;
            }
            if ((int) $this->em->getRepository(WalletTopup::class)->createQueryBuilder('t')->select('COUNT(t.id)')->where('t.account = :account AND t.paidAt IS NULL AND t.createdAt >= :recent')->setParameter('account', $account->getId(), \Doctrine\DBAL\Types\Types::INTEGER)->setParameter('recent', new \DateTimeImmutable('-7 days'), \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)->getQuery()->getSingleScalarResult() >= 25) {
                throw new \DomainException('Bitte zuerst eine vorhandene Aufladung abschließen.');
            }
            $topup = new WalletTopup($account, $cents, $key);
            $this->em->persist($topup);

            return $topup;
        });
    }

    /** Called only after server-side provider verification, or by the authenticated bank booking action. */
    public function confirm(PaymentCheckout $checkout, string $paymentReference, ?User $actor = null): void
    {
        $topup = $checkout->getTopup();
        if (null === $topup || $checkout->getAmountCents() !== $topup->getAmountCents() || $checkout->getCurrency() !== $topup->getCurrency() || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $paymentReference) || ('manual' === $checkout->getProvider() && (null === $actor || !$actor->isAdmin()))) {
            throw new \DomainException('Ungültige Aufladungsbestätigung.');
        }
        $this->em->wrapInTransaction(function () use ($checkout, $topup, $paymentReference, $actor): void {
            $account = $topup->getAccount();
            $this->em->refresh($account, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($topup, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($checkout, LockMode::PESSIMISTIC_WRITE);
            $units = MinuteTariff::centsToUnits($topup->getAmountCents());
            $reference = 'fund:'.$checkout->getProvider().':'.$paymentReference;
            if ($this->ledger->post($account, 'topup', $units, $reference, 'Guthabenaufladung '.$topup->getNumber(), $actor)) {
                $account->credit($topup->getAmountCents());
                $this->notices->customer($account->getCustomer(), 'wallet.funded.'.hash('sha256', $reference), 'Guthabenaufladung bestätigt', 'Dein Zahlungseingang wurde bestätigt und deinem Guthaben gutgeschrieben.', '/marketplace/wallet/'.$topup->getId());
                $topup->credited(new \DateTimeImmutable());
                $this->audit->log($actor, 'wallet.funding.confirmed', ['topup_id' => $topup->getId(), 'provider' => $checkout->getProvider(), 'reference' => $paymentReference, 'amount_cents' => $topup->getAmountCents()]);
            }
            $checkout->complete();
        });
    }
}
