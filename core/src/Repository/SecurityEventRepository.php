<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\SecurityEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecurityEvent>
 */
final class SecurityEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityEvent::class);
    }

    /**
     * @return SecurityEvent[]
     */
    public function findFiltered(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        ?string $ip,
        ?string $rule,
        ?string $source,
        int $limit = 200,
    ): array {
        $builder = $this->createQueryBuilder('event');

        if ($from !== null) {
            $builder->andWhere('event.occurredAt >= :from')->setParameter('from', $from);
        }

        if ($to !== null) {
            $builder->andWhere('event.occurredAt <= :to')->setParameter('to', $to);
        }

        if ($ip !== null && $ip !== '') {
            $builder->andWhere('event.ip = :ip')->setParameter('ip', $ip);
        }

        if ($rule !== null && $rule !== '') {
            $builder->andWhere('event.rule = :rule')->setParameter('rule', $rule);
        }

        if ($source !== null && $source !== '') {
            $builder->andWhere('event.source = :source')->setParameter('source', $source);
        }

        return $builder
            ->orderBy('event.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
