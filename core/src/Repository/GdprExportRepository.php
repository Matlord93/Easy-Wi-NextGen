<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\GdprExport;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\GdprExportStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class GdprExportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GdprExport::class);
    }

    /**
     * @return GdprExport[]
     */
    public function findByCustomer(User $customer): array
    {
        return $this->createQueryBuilder('export')
            ->andWhere('export.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('export.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndCustomer(int $id, User $customer): ?GdprExport
    {
        return $this->createQueryBuilder('export')
            ->andWhere('export.id = :id')
            ->andWhere('export.customer = :customer')
            ->setParameter('id', $id)
            ->setParameter('customer', $customer)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return GdprExport[]
     */
    public function claimPending(int $limit = 25): array
    {
        $limit = max(1, $limit);
        $entityManager = $this->getEntityManager();

        return $entityManager->wrapInTransaction(function () use ($limit, $entityManager): array {
            $exports = $this->createQueryBuilder('export')
                ->andWhere('export.status = :status')
                ->setParameter('status', GdprExportStatus::Pending)
                ->orderBy('export.requestedAt', 'ASC')
                ->setMaxResults($limit)
                ->getQuery()
                ->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE)
                ->getResult();

            foreach ($exports as $export) {
                $export->markRunning();
                $entityManager->persist($export);
            }

            $entityManager->flush();

            return $exports;
        });
    }

    /**
     * @return GdprExport[]
     */
    public function findExpired(\DateTimeImmutable $now, int $limit = 100): array
    {
        return $this->createQueryBuilder('export')
            ->andWhere('export.expiresAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('export.expiresAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}
