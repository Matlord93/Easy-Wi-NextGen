<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\MetricAggregate;
use App\Repository\MailMetricBucketRepository;
use App\Repository\MetricAggregateRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class MetricsDoctrineApiTest extends TestCase
{
    public function testAggregateSeriesBindsParametersWithDoctrineOrmThree(): void
    {
        $em = $this->entityManager();
        (new SchemaTool($em))->createSchema([
            $em->getClassMetadata(Agent::class),
            $em->getClassMetadata(MetricAggregate::class),
        ]);
        $agent = new Agent('metrics-test', ['key_id' => 'key', 'nonce' => 'nonce', 'ciphertext' => 'ciphertext']);
        $start = new \DateTimeImmutable('2026-11-13 12:00:00');
        $aggregate = new MetricAggregate($agent, 'hour', $start);
        $aggregate->ingest(10.0, 20.0, 30.0);
        $em->persist($agent);
        $em->persist($aggregate);
        $em->flush();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        $series = (new MetricAggregateRepository($registry))->findSeries($agent, 'hour', $start);

        self::assertCount(1, $series);
        self::assertEquals($start, $series[0]['bucketStart']);
        self::assertSame(10.0, $series[0]['cpuAvg']);
        self::assertSame([], (new MetricAggregateRepository($registry))->findSeries($agent, 'day', $start));
    }

    public function testMailDimensionsBindsLimitWithDoctrineDbalFour(): void
    {
        $em = $this->entityManager();
        $connection = $em->getConnection();
        $connection->executeStatement('CREATE TABLE mail_metric_buckets (dimensions TEXT, metric_value REAL, metric_name TEXT, bucket_start TEXT)');
        foreach (['alpha' => 10, 'beta' => 5] as $domain => $value) {
            $connection->insert('mail_metric_buckets', [
                'dimensions' => json_encode(['domain' => $domain], JSON_THROW_ON_ERROR),
                'metric_value' => $value,
                'metric_name' => 'delivery.bounce',
                'bucket_start' => '2026-11-13 12:00:00',
            ]);
        }
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        $rows = (new MailMetricBucketRepository($registry))->fetchTopDimensions(
            'delivery.bounce', 'domain', new \DateTimeImmutable('2026-11-13 00:00:00'), new \DateTimeImmutable('2026-11-14 00:00:00'), 1,
        );

        self::assertCount(1, $rows);
        self::assertSame('alpha', $rows[0]['subject']);
        self::assertEquals(10, $rows[0]['value']);
    }

    private function entityManager(): EntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2).'/src/Module/Core/Domain/Entity'], true);
        $configuration->enableNativeLazyObjects(true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));

        return new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $configuration,
        );
    }
}
