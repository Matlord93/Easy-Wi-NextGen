<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConsentLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ConsentLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsentLog::class);
    }

    /**
     * @return ConsentLog[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['acceptedAt' => 'DESC']);
    }
}
