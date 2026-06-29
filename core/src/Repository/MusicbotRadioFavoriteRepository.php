<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioFavorite;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MusicbotRadioFavorite>
 */
final class MusicbotRadioFavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotRadioFavorite::class);
    }

    /** @return MusicbotRadioFavorite[] */
    public function findByCustomer(User $customer): array
    {
        return $this->createQueryBuilder('f')
            ->join('f.station', 's')
            ->where('f.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByCustomerAndStation(User $customer, MusicbotRadioStation $station): ?MusicbotRadioFavorite
    {
        return $this->findOneBy(['customer' => $customer, 'station' => $station]);
    }

    /** @return int[] Station IDs that this customer has favorited. */
    public function getFavoriteStationIds(User $customer): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.station) AS station_id')
            ->where('f.customer = :customer')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r): int => (int) $r['station_id'], $rows);
    }
}
