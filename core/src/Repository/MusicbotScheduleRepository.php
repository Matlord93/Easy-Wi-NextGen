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
        return $this->createQueryBuilder('s')
            ->where('s.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return MusicbotSchedule[] */
    public function findByInstance(MusicbotInstance $instance): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.instance = :instance')
            ->setParameter('instance', $instance)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return MusicbotSchedule[] */
    public function findByCustomerAndInstance(User $customer, MusicbotInstance $instance): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.customer = :customer')
            ->andWhere('s.instance = :instance')
            ->setParameter('customer', $customer)
            ->setParameter('instance', $instance)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return MusicbotSchedule[] */
    public function findDue(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.enabled = true')
            ->andWhere('s.nextRunAt IS NOT NULL')
            ->andWhere('s.nextRunAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('s.nextRunAt', 'ASC')
            ->getQuery()
            ->getResult();
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

        return $qb->getQuery()->getResult();
    }

    public function countByCustomer(User $customer): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.customer = :customer')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
