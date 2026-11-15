<?php

declare(strict_types=1);

namespace App\Repository;

use App\Infrastructure\Config\ScalarValue;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MusicbotRadioStation>
 */
class MusicbotRadioStationRepository extends ServiceEntityRepository
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
        return EntityQueryResult::all($this->createQueryBuilder('r')
            ->where('r.customer = :customer')
            ->andWhere('r.isGlobal = false')
            ->setParameter('customer', QueryParameter::entityId($customer->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->orderBy('r.isFavorite', 'DESC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotRadioStation::class);
    }

    /** @return MusicbotRadioStation[] */
    public function findFavoritesByCustomer(User $customer): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('r')
            ->where('r.customer = :customer')
            ->andWhere('r.isGlobal = false')
            ->andWhere('r.isFavorite = true')
            ->setParameter('customer', QueryParameter::entityId($customer->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->orderBy('r.name', 'ASC')
            ->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotRadioStation::class);
    }

    /** @return MusicbotRadioStation[] */
    public function findRecentlyPlayed(User $customer, int $limit = 10): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('r')
            ->where('r.customer = :customer')
            ->andWhere('r.lastPlayedAt IS NOT NULL')
            ->setParameter('customer', QueryParameter::entityId($customer->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->orderBy('r.lastPlayedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotRadioStation::class);
    }

    public function findOneForCustomer(int $id, User $customer): ?MusicbotRadioStation
    {
        return EntityQueryResult::one($this->createQueryBuilder('r')
            ->where('r.id = :id')
            ->andWhere('r.customer = :customer')
            ->setParameter('id', $id)
            ->setParameter('customer', QueryParameter::entityId($customer->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotRadioStation::class);
    }

    public function countByCustomer(User $customer): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.customer = :customer')
            ->andWhere('r.isGlobal = false')
            ->setParameter('customer', QueryParameter::entityId($customer->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->getQuery()
            ->getSingleScalarResult();
    }

    // -------------------------------------------------------------------------
    // Global catalog
    // -------------------------------------------------------------------------

    /**
     * @param array<array-key, mixed> $filters
     * @return list<MusicbotRadioStation>
     */
    public function searchCatalog(array $filters = []): array
    {
        $qb = $this->catalogQuery($filters)
            ->orderBy('r.name', 'ASC')
            ->setMaxResults(min(200, max(1, ScalarValue::integer($filters['limit'] ?? null, 50))))
            ->setFirstResult(max(0, ScalarValue::integer($filters['offset'] ?? null, 0)));

        return EntityQueryResult::all($qb->getQuery(), MusicbotRadioStation::class);
    }

    /** @param array<array-key, mixed> $filters */
    public function countCatalog(array $filters = []): int
    {
        return (int) $this->catalogQuery($filters)
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param array<array-key, mixed> $filters */
    private function catalogQuery(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r')->where('r.isGlobal = true');
        if ((bool) ($filters['active_only'] ?? true)) {
            $qb->andWhere('r.isActive = true');
        }

        foreach (['query', 'genre', 'country', 'language', 'format'] as $field) {
            $value = self::filterText($filters[$field] ?? null);
            if ($value === null) {
                continue;
            }
            $condition = match ($field) {
                'query' => 'LOWER(r.name) LIKE :query OR LOWER(r.genre) LIKE :query OR LOWER(r.description) LIKE :query OR LOWER(r.country) LIKE :query',
                'genre' => 'LOWER(r.genre) LIKE :genre',
                default => sprintf('LOWER(r.%s) = :%s', $field, $field),
            };
            $qb->andWhere($condition)->setParameter(
                $field,
                in_array($field, ['query', 'genre'], true) ? '%' . $value . '%' : $value
            );
        }

        foreach (['min_bitrate' => '>=', 'max_bitrate' => '<='] as $field => $comparison) {
            $value = ScalarValue::integer($filters[$field] ?? null, 0);
            if ($value > 0) {
                $qb->andWhere(sprintf('r.bitrate %s :%s', $comparison, $field))->setParameter($field, $value);
            }
        }

        return $qb;
    }

    private static function filterText(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_strtolower($value) : null;
    }

    /** @return MusicbotRadioStation[] */
    public function findPopularCatalogStations(array $popularIds, int $limit = 20): array
    {
        if ($popularIds === []) {
            return EntityQueryResult::all($this->createQueryBuilder('r')
                ->where('r.isGlobal = true')
                ->andWhere('r.isActive = true')
                ->orderBy('r.name', 'ASC')
                ->setMaxResults($limit)
                ->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotRadioStation::class);
        }

        return EntityQueryResult::all($this->createQueryBuilder('r')
            ->where('r.isGlobal = true')
            ->andWhere('r.isActive = true')
            ->andWhere('r.id IN (:ids)')
            ->setParameter('ids', $popularIds)
            ->orderBy('r.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotRadioStation::class);
    }

    /** @return MusicbotRadioStation[] */
    public function findNewestCatalogStations(int $limit = 20): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('r')
            ->where('r.isGlobal = true')
            ->andWhere('r.isActive = true')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotRadioStation::class);
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

        return array_values(array_filter($rows, static fn (mixed $value): bool => is_string($value) && $value !== ''));
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

        return array_values(array_filter($rows, static fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    // -------------------------------------------------------------------------
    // Admin
    // -------------------------------------------------------------------------

    /** @return MusicbotRadioStation[] All customer-submitted (private) stations awaiting promotion. */
    public function findPendingCustomerStations(int $limit = 100): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('r')
            ->where('r.isGlobal = false')
            ->andWhere('r.customer IS NOT NULL')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotRadioStation::class);
    }

    /** @return MusicbotRadioStation[] */
    public function findAllGlobal(bool $includeInactive = true): array
    {
        $qb = $this->createQueryBuilder('r')->where('r.isGlobal = true');
        if (!$includeInactive) {
            $qb->andWhere('r.isActive = true');
        }

        return EntityQueryResult::all($qb->orderBy('r.name', 'ASC')->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotRadioStation::class);
    }
}
