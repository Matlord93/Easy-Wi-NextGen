<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ContactMessage;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactMessage>
 */
final class ContactMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactMessage::class);
    }

    /** @return list<ContactMessage> */
    public function findBySite(Site $site, int $limit = 50, int $offset = 0): array
    {
        /** @var list<ContactMessage> $rows */
        $rows = $this->createQueryBuilder('m')
            ->andWhere('m.site = :site')
            ->setParameter('site', $site)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countBySite(Site $site): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.site = :site')
            ->setParameter('site', $site)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countNewBySite(Site $site): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.site = :site')
            ->andWhere('m.status = :status')
            ->setParameter('site', $site)
            ->setParameter('status', ContactMessage::STATUS_NEW)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countRecentByIp(string $ip, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.ipAddress = :ip')
            ->andWhere('m.createdAt >= :since')
            ->setParameter('ip', $ip)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
