<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Enum\InvoiceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
        return $this->createQueryBuilder('invoice')
            ->orderBy('invoice.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
    public function findDunnable(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('invoice')
            ->andWhere('invoice.status IN (:statuses)')
            ->andWhere('invoice.dueDate <= :now')
            ->setParameter('statuses', [InvoiceStatus::Open, InvoiceStatus::PastDue])
            ->setParameter('now', $now)
            ->orderBy('invoice.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
