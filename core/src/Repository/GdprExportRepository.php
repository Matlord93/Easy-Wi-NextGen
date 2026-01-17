<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\GdprExport;
use App\Module\Core\Domain\Entity\User;
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
}
