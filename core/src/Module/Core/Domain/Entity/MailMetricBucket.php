<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MailMetricBucketRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MailMetricBucketRepository::class)]
#[ORM\Table(name: 'mail_metric_buckets')]
#[ORM\Index(name: 'idx_mail_metric_buckets_bucket', columns: ['bucket_start', 'bucket_size_seconds'])]
#[ORM\Index(name: 'idx_mail_metric_buckets_metric', columns: ['metric_name', 'bucket_start'])]
#[ORM\Index(name: 'idx_mail_metric_buckets_domain_metric', columns: ['domain_id', 'metric_name', 'bucket_start'])]
class MailMetricBucket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Domain $domain = null;

    #[ORM\Column]
    private \DateTimeImmutable $bucketStart;

    #[ORM\Column]
    private int $bucketSizeSeconds;

    #[ORM\Column(length: 64)]
    private string $metricName;

    #[ORM\Column(type: 'float')]
    private float $metricValue;

    /** @var array<string,mixed> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $dimensions = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param array<string,mixed> $dimensions */
    public function __construct(?Domain $domain, \DateTimeImmutable $bucketStart, int $bucketSizeSeconds, string $metricName, float $metricValue, array $dimensions = [])
    {
        $this->domain = $domain;
        $this->bucketStart = $bucketStart;
        $this->bucketSizeSeconds = max(60, $bucketSizeSeconds);
        $this->metricName = strtolower(trim($metricName));
        $this->metricValue = $metricValue;
        $this->dimensions = $dimensions;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getBucketStart(): \DateTimeImmutable
    {
        return $this->bucketStart;
    }

    public function getBucketSizeSeconds(): int
    {
        return $this->bucketSizeSeconds;
    }

    public function getMetricName(): string
    {
        return $this->metricName;
    }

    public function getMetricValue(): float
    {
        return $this->metricValue;
    }

    public function getDomain(): ?Domain
    {
        return $this->domain;
    }

    /** @return array<string,mixed> */
    public function getDimensions(): array
    {
        return $this->dimensions;
    }
}
