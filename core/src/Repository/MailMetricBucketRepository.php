<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\MailMetricBucket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

final class MailMetricBucketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailMetricBucket::class);
    }

    /** @return array<string,mixed> */
    public function fetchOverview(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return [
            'queue_depth' => $this->sumMetric($conn, 'queue.depth', $from, $to),
            'deferred_mails' => $this->sumMetric($conn, 'queue.deferred', $from, $to),
            'bounces' => $this->sumMetric($conn, 'delivery.bounce', $from, $to),
            'dkim_failures' => $this->sumMetric($conn, 'dkim.failures', $from, $to),
            'auth_failures' => $this->sumMetric($conn, 'auth.failures', $from, $to),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function fetchQueueSeries(\DateTimeImmutable $from, \DateTimeImmutable $to, int $bucketSeconds = 300): array
    {
        $sql = <<<'SQL'
SELECT bucket_start, SUM(metric_value) AS value
FROM mail_metric_buckets
WHERE metric_name IN ('queue.depth', 'queue.deferred')
  AND bucket_size_seconds = :bucket
  AND bucket_start BETWEEN :from AND :to
GROUP BY bucket_start
ORDER BY bucket_start ASC
SQL;

        return $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, [
            'bucket' => $bucketSeconds,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function fetchTopDimensions(string $metricName, string $dimensionKey, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 10): array
    {
        $sql = <<<'SQL'
SELECT COALESCE(dimensions->>:dimensionKey, 'unknown') AS subject, SUM(metric_value) AS value
FROM mail_metric_buckets
WHERE metric_name = :metric
  AND bucket_start BETWEEN :from AND :to
GROUP BY subject
ORDER BY value DESC
LIMIT :limit
SQL;

        return $this->getEntityManager()->getConnection()->executeQuery($sql, [
            'dimensionKey' => $dimensionKey,
            'metric' => $metricName,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'limit' => max(1, min($limit, 100)),
        ], [
            'limit' => \PDO::PARAM_INT,
        ])->fetchAllAssociative();
    }

    private function sumMetric(Connection $conn, string $metricName, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $sql = <<<'SQL'
SELECT COALESCE(SUM(metric_value), 0) AS value
FROM mail_metric_buckets
WHERE metric_name = :metric
  AND bucket_start BETWEEN :from AND :to
SQL;

        return (float) $conn->fetchOne($sql, [
            'metric' => $metricName,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ]);
    }
}
