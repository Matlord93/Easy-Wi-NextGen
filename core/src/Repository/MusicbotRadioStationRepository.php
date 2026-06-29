<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MusicbotRadioStation>
 */
final class MusicbotRadioStationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotRadioStation::class);
    }

    // -------------------------------------------------------------------------
    // Customer private stations
    // -------------------------------------------------------------------------

    /** @return MusicbotRadioStation[] */
    public function findByCustomer(User $customer): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.customer = :customer')
            ->andWhere('r.isGlobal = false')
            ->setParameter('customer', $customer)
            ->orderBy('r.isFavorite', 'DESC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return MusicbotRadioStation[] */
    public function findFavoritesByCustomer(User $customer): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.customer = :customer')
            ->andWhere('r.isGlobal = false')
            ->andWhere('r.isFavorite = true')
            ->setParameter('customer', $customer)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return MusicbotRadioStation[] */
    public function findRecentlyPlayed(User $customer, int $limit = 10): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.customer = :customer')
            ->andWhere('r.lastPlayedAt IS NOT NULL')
            ->setParameter('customer', $customer)
            ->orderBy('r.lastPlayedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneForCustomer(int $id, User $customer): ?MusicbotRadioStation
    {
        return $this->createQueryBuilder('r')
            ->where('r.id = :id')
            ->andWhere('r.customer = :customer')
            ->setParameter('id', $id)
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countByCustomer(User $customer): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.customer = :customer')
            ->andWhere('r.isGlobal = false')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // -------------------------------------------------------------------------
    // Global catalog
    // -------------------------------------------------------------------------

    /**
     * Search the global catalog with optional filters.
     *
     * @param array{query?: string, genre?: string, country?: string, language?: string, format?: string, min_bitrate?: int, max_bitrate?: int, active_only?: bool, limit?: int, offset?: int} $filters
     * @return MusicbotRadioStation[]
     */
    public function searchCatalog(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.isGlobal = true');

        $activeOnly = (bool) ($filters['active_only'] ?? true);
        if ($activeOnly) {
            $qb->andWhere('r.isActive = true');
        }

        if (!empty($filters['query'])) {
            $q = '%' . mb_strtolower($filters['query']) . '%';
            $qb->andWhere('LOWER(r.name) LIKE :q OR LOWER(r.genre) LIKE :q OR LOWER(r.description) LIKE :q OR LOWER(r.country) LIKE :q')
               ->setParameter('q', $q);
        }

        if (!empty($filters['genre'])) {
            $qb->andWhere('LOWER(r.genre) LIKE :genre')
               ->setParameter('genre', '%' . mb_strtolower($filters['genre']) . '%');
        }

        if (!empty($filters['country'])) {
            $qb->andWhere('LOWER(r.country) = :country')
               ->setParameter('country', mb_strtolower($filters['country']));
        }

        if (!empty($filters['language'])) {
            $qb->andWhere('LOWER(r.language) = :language')
               ->setParameter('language', mb_strtolower($filters['language']));
        }

        if (!empty($filters['format'])) {
            $qb->andWhere('LOWER(r.format) = :format')
               ->setParameter('format', mb_strtolower($filters['format']));
        }

        if (isset($filters['min_bitrate']) && $filters['min_bitrate'] > 0) {
            $qb->andWhere('r.bitrate >= :min_bitrate')
               ->setParameter('min_bitrate', (int) $filters['min_bitrate']);
        }

        if (isset($filters['max_bitrate']) && $filters['max_bitrate'] > 0) {
            $qb->andWhere('r.bitrate <= :max_bitrate')
               ->setParameter('max_bitrate', (int) $filters['max_bitrate']);
        }

        $limit = min(200, max(1, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $qb->orderBy('r.name', 'ASC')
           ->setMaxResults($limit)
           ->setFirstResult($offset);

        return $qb->getQuery()->getResult();
    }

    public function countCatalog(array $filters = []): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.isGlobal = true');

        $activeOnly = (bool) ($filters['active_only'] ?? true);
        if ($activeOnly) {
            $qb->andWhere('r.isActive = true');
        }

        if (!empty($filters['query'])) {
            $q = '%' . mb_strtolower($filters['query']) . '%';
            $qb->andWhere('LOWER(r.name) LIKE :q OR LOWER(r.genre) LIKE :q OR LOWER(r.description) LIKE :q OR LOWER(r.country) LIKE :q')
               ->setParameter('q', $q);
        }

        if (!empty($filters['genre'])) {
            $qb->andWhere('LOWER(r.genre) LIKE :genre')
               ->setParameter('genre', '%' . mb_strtolower($filters['genre']) . '%');
        }

        if (!empty($filters['country'])) {
            $qb->andWhere('LOWER(r.country) = :country')
               ->setParameter('country', mb_strtolower($filters['country']));
        }

        if (!empty($filters['language'])) {
            $qb->andWhere('LOWER(r.language) = :language')
               ->setParameter('language', mb_strtolower($filters['language']));
        }

        if (!empty($filters['format'])) {
            $qb->andWhere('LOWER(r.format) = :format')
               ->setParameter('format', mb_strtolower($filters['format']));
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return MusicbotRadioStation[] */
    public function findPopularCatalogStations(array $popularIds, int $limit = 20): array
    {
        if ($popularIds === []) {
            return $this->createQueryBuilder('r')
                ->where('r.isGlobal = true')
                ->andWhere('r.isActive = true')
                ->orderBy('r.name', 'ASC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
        }

        return $this->createQueryBuilder('r')
            ->where('r.isGlobal = true')
            ->andWhere('r.isActive = true')
            ->andWhere('r.id IN (:ids)')
            ->setParameter('ids', $popularIds)
            ->orderBy('r.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return MusicbotRadioStation[] */
    public function findNewestCatalogStations(int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.isGlobal = true')
            ->andWhere('r.isActive = true')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return string[] */
    public function getDistinctGenres(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('DISTINCT r.genre')
            ->where('r.isGlobal = true')
            ->andWhere('r.isActive = true')
            ->andWhere('r.genre IS NOT NULL')
            ->orderBy('r.genre', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($rows));
    }

    /** @return string[] */
    public function getDistinctCountries(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('DISTINCT r.country')
            ->where('r.isGlobal = true')
            ->andWhere('r.isActive = true')
            ->andWhere('r.country IS NOT NULL')
            ->orderBy('r.country', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($rows));
    }

    // -------------------------------------------------------------------------
    // Admin
    // -------------------------------------------------------------------------

    /** @return MusicbotRadioStation[] All customer-submitted (private) stations awaiting promotion. */
    public function findPendingCustomerStations(int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.isGlobal = false')
            ->andWhere('r.customer IS NOT NULL')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return MusicbotRadioStation[] */
    public function findAllGlobal(bool $includeInactive = true): array
    {
        $qb = $this->createQueryBuilder('r')->where('r.isGlobal = true');
        if (!$includeInactive) {
            $qb->andWhere('r.isActive = true');
        }

        return $qb->orderBy('r.name', 'ASC')->getQuery()->getResult();
    }
}
