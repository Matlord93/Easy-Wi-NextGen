<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceSchedule;
use App\Module\Core\Domain\Enum\InstanceScheduleAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstanceSchedule>
 */
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

        return EntityQueryResult::all($this->createQueryBuilder('schedule')
            ->andWhere('schedule.instance IN (:instances)')
            ->andWhere('schedule.action = :action')
            ->setParameter('instances', $instances)
            ->setParameter('action', $action->value)
            ->getQuery(), \App\Module\Core\Domain\Entity\InstanceSchedule::class);
    }

    public function findOneByInstanceAndAction(Instance $instance, InstanceScheduleAction $action): ?InstanceSchedule
    {
        return $this->findOneBy([
            'instance' => $instance,
            'action' => $action,
        ]);
    }

    /**
     * @return InstanceSchedule[]
     */
    public function findEnabledBatchAfterId(int $afterId, int $limit): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('schedule')
            ->andWhere('schedule.enabled = :enabled')
            ->andWhere('schedule.id > :afterId')
            ->setParameter('enabled', true)
            ->setParameter('afterId', $afterId)
            ->orderBy('schedule.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery(), \App\Module\Core\Domain\Entity\InstanceSchedule::class);
    }
}
