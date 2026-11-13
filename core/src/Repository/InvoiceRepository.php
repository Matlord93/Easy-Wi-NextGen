<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InvoiceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
final class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * @return Invoice[]
     */
    public function findRecent(int $limit = 10): array
    {
        /** @var list<Invoice> $hydratedEntities */
        $hydratedEntities = $this->createQueryBuilder('invoice')
            ->orderBy('invoice.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $hydratedEntities;
    }

    public function countByStatus(InvoiceStatus $status): int
    {
        return (int) $this->createQueryBuilder('invoice')
            ->select('COUNT(invoice.id)')
            ->andWhere('invoice.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Invoice[]
     */
    public function findByCustomer(User $customer): array
    {
        /** @var list<Invoice> $hydratedEntities */
        $hydratedEntities = $this->createQueryBuilder('invoice')
            ->andWhere('invoice.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('invoice.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $hydratedEntities;
    }

    /**
     * @return Invoice[]
     */
    public function findDunnable(\DateTimeImmutable $now): array
    {
        /** @var list<Invoice> $hydratedEntities */
        $hydratedEntities = $this->createQueryBuilder('invoice')
            ->andWhere('invoice.status IN (:statuses)')
            ->andWhere('invoice.dueDate <= :now')
            ->setParameter('statuses', [InvoiceStatus::Open->value, InvoiceStatus::PastDue->value])
            ->setParameter('now', $now)
            ->orderBy('invoice.dueDate', 'ASC')
            ->getQuery()
            ->getResult();

        return $hydratedEntities;
    }

    /**
     * @return Invoice[]
     */
    public function findForExport(?int $year = null): array
    {
        $queryBuilder = $this->createQueryBuilder('invoice')
            ->orderBy('invoice.createdAt', 'ASC');

        if ($year !== null) {
            $start = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year));
            $end = $start->modify('+1 year');
            $queryBuilder
                ->andWhere('invoice.createdAt >= :start')
                ->andWhere('invoice.createdAt < :end')
                ->setParameter('start', $start)
                ->setParameter('end', $end);
        }

        /** @var list<Invoice> $hydratedEntities */
        $hydratedEntities = $queryBuilder->getQuery()->getResult();

        return $hydratedEntities;
    }
}
