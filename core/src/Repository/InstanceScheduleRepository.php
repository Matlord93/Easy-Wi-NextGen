<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Instance;
use App\Entity\InstanceSchedule;
use App\Enum\InstanceScheduleAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class InstanceScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstanceSchedule::class);
    }

    /**
     * @param Instance[] $instances
     *
     * @return InstanceSchedule[]
     */
    public function findByInstancesAndAction(array $instances, InstanceScheduleAction $action): array
    {
        if ($instances === []) {
            return [];
        }

        return $this->createQueryBuilder('schedule')
            ->andWhere('schedule.instance IN (:instances)')
            ->andWhere('schedule.action = :action')
            ->setParameter('instances', $instances)
            ->setParameter('action', $action)
            ->getQuery()
            ->getResult();
    }

    public function findOneByInstanceAndAction(Instance $instance, InstanceScheduleAction $action): ?InstanceSchedule
    {
        return $this->findOneBy([
            'instance' => $instance,
            'action' => $action,
        ]);
    }
}
