<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotSchedule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MusicbotSchedule>
 */
class MusicbotScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotSchedule::class);
    }

    /** @return MusicbotSchedule[] */
    public function findByCustomer(User $customer): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('s')
            ->where('s.customer = :customer')
            ->setParameter('customer', QueryParameter::entityId($customer->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotSchedule::class);
    }

    /** @return MusicbotSchedule[] */
    public function findByInstance(MusicbotInstance $instance): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('s')
            ->where('s.instance = :instance')
            ->setParameter('instance', QueryParameter::entityId($instance->getId(), \App\Module\Musicbot\Domain\Entity\MusicbotInstance::class))
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotSchedule::class);
    }

    /** @return MusicbotSchedule[] */
    public function findByCustomerAndInstance(User $customer, MusicbotInstance $instance): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('s')
            ->where('s.customer = :customer')
            ->andWhere('s.instance = :instance')
            ->setParameter('customer', QueryParameter::entityId($customer->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->setParameter('instance', QueryParameter::entityId($instance->getId(), \App\Module\Musicbot\Domain\Entity\MusicbotInstance::class))
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotSchedule::class);
    }

    /** @return MusicbotSchedule[] */
    public function findDue(\DateTimeImmutable $now): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('s')
            ->where('s.enabled = true')
            ->andWhere('s.nextRunAt IS NOT NULL')
            ->andWhere('s.nextRunAt <= :now')
            ->setParameter('now', $now, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->orderBy('s.nextRunAt', 'ASC')
            ->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotSchedule::class);
    }

    /**
     * @param array{customer_id?: int, instance_id?: int} $filters
     * @return MusicbotSchedule[]
     */
    public function findForAdmin(array $filters = [], int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.updatedAt', 'DESC')
            ->setMaxResults($limit);

        if (isset($filters['customer_id'])) {
            $qb->andWhere('s.customer = :customerId')->setParameter('customerId', $filters['customer_id']);
        }
        if (isset($filters['instance_id'])) {
            $qb->andWhere('s.instance = :instanceId')->setParameter('instanceId', $filters['instance_id']);
        }

        return EntityQueryResult::all($qb->getQuery(), \App\Module\Musicbot\Domain\Entity\MusicbotSchedule::class);
    }

    public function countByCustomer(User $customer): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.customer = :customer')
            ->setParameter('customer', QueryParameter::entityId($customer->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->getQuery()
            ->getSingleScalarResult();
    }
}
