<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioHistory;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MusicbotRadioHistory>
 */
class MusicbotRadioHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotRadioHistory::class);
    }

    /** @return MusicbotRadioHistory[] */
    public function findByCustomer(User $customer, int $limit = 20): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('h.playedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return MusicbotRadioHistory[] */
    public function findByCustomerAndInstance(User $customer, MusicbotInstance $instance, int $limit = 20): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.customer = :customer')
            ->andWhere('h.instance = :instance')
            ->setParameter('customer', $customer)
            ->setParameter('instance', $instance)
            ->orderBy('h.playedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Prune history entries older than $keepDays days for the customer. */
    public function pruneOld(User $customer, int $keepRows = 100): void
    {
        $subQb = $this->createQueryBuilder('h2')
            ->select('h2.id')
            ->where('h2.customer = :customer')
            ->orderBy('h2.playedAt', 'DESC')
            ->setMaxResults($keepRows);

        $this->createQueryBuilder('h')
            ->delete()
            ->where('h.customer = :customer')
            ->andWhere('h.id NOT IN (' . $subQb->getDQL() . ')')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->execute();
    }

    /** @return array<int,int> stationId => playCount, sorted desc */
    public function getPopularStationIds(int $limit = 20): array
    {
        $rows = $this->createQueryBuilder('h')
            ->select('IDENTITY(h.station) AS station_id, COUNT(h.id) AS play_count')
            ->groupBy('h.station')
            ->orderBy('play_count', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['station_id']] = (int) $row['play_count'];
        }

        return $result;
    }
}
