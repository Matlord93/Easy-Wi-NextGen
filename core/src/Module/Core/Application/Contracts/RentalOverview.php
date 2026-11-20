<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Domain\Entity\AppSetting;
use App\Module\Core\Domain\Entity\RentalNotice;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\WalletEntry;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Core\Domain\ValueObject\MinuteTariff;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RentalOverview
{
    public function __construct(private EntityManagerInterface $em, private RentalNotices $notices, private RentalSettings $settings)
    {
    }

    /** @return list<array{contract:ShopContract,pending_units:int,forecast_units:int,remaining_minutes:int}> */
    public function costs(User $customer, \DateTimeImmutable $now): array
    {
        /** @var list<ShopContract> $contracts */
        $contracts = $this->em->getRepository(ShopContract::class)->createQueryBuilder('c')->join('c.account', 'a')->where("a.customer = :customer AND c.status = 'active'")->setParameter('customer', $customer->getId(), \Doctrine\DBAL\Types\Types::INTEGER)->orderBy('c.id')->getQuery()->getResult();
        $rows = [];
        $monthEnd = $now->modify('first day of next month')->setTime(0, 0);
        foreach ($contracts as $contract) {
            if (ShopProductKind::Domain === $contract->getKind()) {
                continue;
            }
            $startedAt = $contract->getStartedAt();
            $minutes = null !== $startedAt ? max($contract->getChargedMinutes(), MinuteTariff::minutes($startedAt, $now)) : 0;
            $unmetered = max(0, $minutes - $contract->getChargedMinutes()) * $contract->getMonthlyCents();
            $remaining = MinuteTariff::minutes($now, $monthEnd);
            $pending = $contract->getPendingUnits() + $unmetered;
            $rows[] = ['contract' => $contract, 'pending_units' => $pending, 'forecast_units' => $pending + $remaining * $contract->getMonthlyCents(), 'remaining_minutes' => $remaining];
        }

        return $rows;
    }

    public function observe(\DateTimeImmutable $now): void
    {
        $minute = $now->setTime((int) $now->format('H'), (int) $now->format('i'));
        /** @var list<ShopContract> $contracts */
        $contracts = $this->em->getRepository(ShopContract::class)->createQueryBuilder('c')->where('c.checkedAt >= :minute')->setParameter('minute', $minute, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)->orderBy('c.id')->setMaxResults(500)->getQuery()->getResult();
        foreach ($contracts as $contract) {
            $this->em->wrapInTransaction(function () use ($contract, $now): void {
                $this->em->refresh($contract->getAccount(), LockMode::PESSIMISTIC_WRITE);
                $end = $contract->getTermEndsAt();
                if (null !== $end && null === $contract->getEndedAt() && $end > $now) {
                    foreach ([30, 7, 1] as $days) {
                        if ($end <= $now->modify('+'.$days.' days')) {
                            $this->notices->contract($contract, 'domain-expiry.'.$end->format('Ymd').'.'.$days, 'Domainlaufzeit endet bald', 'Die feste Domainlaufzeit endet am '.$end->format('d.m.Y').'. Den Verlängerungs- oder Kündigungsstatus findest du im Vertrag.');
                        }
                    }
                }
                $account = $contract->getAccount();
                if ('active' === $contract->getStatus() && 'wallet' === $contract->getBillingMode() && $contract->getMonthlyCents() > 0 && $account->getAvailableUnits() < MinuteTariff::centsToUnits((int) $this->settings->get()['low_wallet_cents'])) {
                    $lastCredit = $this->em->getRepository(WalletEntry::class)->createQueryBuilder('e')->select('MAX(e.id)')->where('e.account = :account AND e.units > 0')->setParameter('account', $account->getId(), \Doctrine\DBAL\Types\Types::INTEGER)->getQuery()->getSingleScalarResult();
                    $this->notices->customer($account->getCustomer(), 'wallet.low.'.($account->getId() ?? 0).'.'.(int) $lastCredit, 'Dein Guthaben wird knapp', 'Bitte lade dein Guthaben rechtzeitig auf. Bei nicht ausreichendem Guthaben endet die weitere Nutzung.', '/marketplace/wallet');
                }
            });
        }
        $heartbeat = $this->em->find(AppSetting::class, 'rental_last_success') ?? new AppSetting('rental_last_success', []);
        $heartbeat->setValue(['at' => $now->format(DATE_ATOM), 'contracts_observed' => count($contracts)]);
        $this->em->persist($heartbeat);
        $this->em->flush();
    }

    /** @return array<string,int> */
    public function health(): array
    {
        $counts = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT status, COUNT(*) AS amount FROM shop_contracts GROUP BY status') as $row) {
            if (is_string($row['status'])) {
                $counts[$row['status']] = (int) $row['amount'];
            }
        }
        $counts['mail_failed'] = (int) $this->em->getRepository(RentalNotice::class)->createQueryBuilder('n')->select('COUNT(n.id)')->where('n.sentAt IS NULL AND n.attempts >= 5')->getQuery()->getSingleScalarResult();

        return $counts;
    }
}
