<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\CreditNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class CreditNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CreditNote::class);
    }

    /**
     * @return CreditNote[]
     */
    public function findRecent(int $limit = 5): array
    {
        return $this->createQueryBuilder('credit')
            ->orderBy('credit.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
