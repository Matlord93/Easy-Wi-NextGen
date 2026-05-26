<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\TeamspeakUpdateLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class TeamspeakUpdateLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, TeamspeakUpdateLog::class); }
    public function findLatestForInstance(string $instanceType, int $instanceId, int $limit = 10): array
    {
        return $this->createQueryBuilder('l')->andWhere('l.instanceType=:t')->andWhere('l.instanceId=:i')->setParameter('t',$instanceType)->setParameter('i',$instanceId)->orderBy('l.startedAt','DESC')->setMaxResults($limit)->getQuery()->getResult();
    }
}
