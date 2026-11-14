<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailLog>
 */
final class MailLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailLog::class);
    }

    /**
     * @return MailLog[]
     */
    public function findByAdminFilters(?Domain $domain, ?string $level, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('ml')
            ->orderBy('ml.createdAt', 'DESC')
            ->setMaxResults(max(1, min($limit, 1000)));

        if ($domain !== null) {
            $qb->andWhere('ml.domain = :domain')->setParameter('domain', $domain);
        }
        if ($level !== null && $level !== '') {
            $qb->andWhere('ml.level = :level')->setParameter('level', strtolower(trim($level)));
        }
        if ($from !== null) {
            $qb->andWhere('ml.createdAt >= :from')->setParameter('from', $from, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE);
        }
        if ($to !== null) {
            $qb->andWhere('ml.createdAt <= :to')->setParameter('to', $to, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE);
        }

        return EntityQueryResult::all($qb->getQuery(), \App\Module\Core\Domain\Entity\MailLog::class);
    }

    /**
     * @return array<string,int>
     */
    public function countByLevelSince(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('ml')
            ->select('ml.level AS level, COUNT(ml.id) AS total')
            ->andWhere('ml.createdAt >= :since')
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->groupBy('ml.level')
            ->getQuery()
            ->getArrayResult();

        $summary = [
            MailLog::LEVEL_INFO => 0,
            MailLog::LEVEL_WARNING => 0,
            MailLog::LEVEL_ERROR => 0,
            MailLog::LEVEL_CRITICAL => 0,
        ];

        foreach ($rows as $row) {
            $level = (string) ($row['level'] ?? '');
            if (array_key_exists($level, $summary)) {
                $summary[$level] = (int) ($row['total'] ?? 0);
            }
        }

        return $summary;
    }

    /**
     * @return array<string,int>
     */
    public function countBySourceSince(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('ml')
            ->select('ml.source AS source, COUNT(ml.id) AS total')
            ->andWhere('ml.createdAt >= :since')
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->groupBy('ml.source')
            ->getQuery()
            ->getArrayResult();

        $summary = [];
        foreach ($rows as $row) {
            $source = (string) ($row['source'] ?? '');
            if ($source !== '') {
                $summary[$source] = (int) ($row['total'] ?? 0);
            }
        }

        return $summary;
    }

    /**
     * @return MailLog[]
     */
    public function findRecent(int $limit = 20): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('ml')
            ->orderBy('ml.createdAt', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)))
            ->getQuery(), \App\Module\Core\Domain\Entity\MailLog::class);
    }

    /** @return MailLog[] */
    public function findSuspiciousRecent(int $limit = 50): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('ml')
            ->andWhere('ml.eventType = :spam OR ml.level IN (:levels) OR LOWER(ml.message) LIKE :phishing OR LOWER(ml.message) LIKE :virus OR LOWER(ml.message) LIKE :malware')
            ->setParameter('spam', MailLog::EVENT_SPAM)
            ->setParameter('levels', [MailLog::LEVEL_ERROR, MailLog::LEVEL_CRITICAL])
            ->setParameter('phishing', '%phish%')
            ->setParameter('virus', '%virus%')
            ->setParameter('malware', '%malware%')
            ->orderBy('ml.createdAt', 'DESC')
            ->setMaxResults(max(1, min($limit, 200)))
            ->getQuery(), \App\Module\Core\Domain\Entity\MailLog::class);
    }
}
