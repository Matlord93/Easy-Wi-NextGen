<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\InstanceMetricSampleRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InstanceMetricSampleRepositoryTest extends KernelTestCase
{
    public function testAdminBrowseQueryBuilderIsBoundedAndScalar(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var InstanceMetricSampleRepository $repository */
        $repository = $container->get(InstanceMetricSampleRepository::class);

        $qb = $repository->buildAdminBrowseQueryBuilder(
            new \DateTimeImmutable('-60 minutes'),
            1,
            500,
            null,
            null,
            null,
        );

        self::assertSame(200, $qb->getMaxResults());
        self::assertStringContainsString('sample.collectedAt >= :since', $qb->getDQL());
        self::assertStringContainsString('sample.cpuPercent', $qb->getDQL());
        self::assertStringNotContainsString('sample.payload', $qb->getDQL());
    }

    public function testLatestByInstancesUsesDatabaseGroupingInsteadOfHydratingHistory(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var InstanceMetricSampleRepository $repository */
        $repository = $container->get(InstanceMetricSampleRepository::class);
        $class = new \ReflectionClass($repository);

        // The public method returns early for an empty set. Inspect the source
        // via reflection to ensure the bounded query strategy remains in place
        // without needing a large fixture history.
        $source = (string) file_get_contents((string) $class->getFileName());

        self::assertStringContainsString("select('MAX(latest.id)')", $source);
        self::assertStringContainsString("groupBy('latest.instance')", $source);
        self::assertStringContainsString("sample.id IN", $source);
    }

    public function testExcessCleanupUsesBoundedBatches(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var InstanceMetricSampleRepository $repository */
        $repository = $container->get(InstanceMetricSampleRepository::class);
        $source = (string) file_get_contents((string) (new \ReflectionClass($repository))->getFileName());

        self::assertStringContainsString('setFirstResult($maximumSamples)', $source);
        self::assertStringContainsString('setMaxResults($batchSize)', $source);
        self::assertStringContainsString("sample.id IN (:ids)", $source);
    }
    public function testCleanupReportsCountsAndRetainsNewestSamplesAcrossBatches(): void
    {
        self::bootKernel();
        $configuredManager = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        self::assertInstanceOf(\Doctrine\ORM\EntityManagerInterface::class, $configuredManager);
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $manager = new \Doctrine\ORM\EntityManager($connection, $configuredManager->getConfiguration());
        $connection->executeStatement('CREATE TABLE instance_metric_samples (id INTEGER PRIMARY KEY, instance_id INTEGER NOT NULL, cpu_percent REAL, mem_used_bytes BIGINT, tasks_current INTEGER, collected_at DATETIME NOT NULL, error_code VARCHAR(120))');
        $registry = $this->createMock(\Doctrine\Persistence\ManagerRegistry::class);
        $registry->expects(self::once())->method('getManagerForClass')->willReturn($manager);
        $repository = new InstanceMetricSampleRepository($registry);
        $instance = $manager->getReference(\App\Module\Core\Domain\Entity\Instance::class, 123);
        self::assertInstanceOf(\App\Module\Core\Domain\Entity\Instance::class, $instance);

        try {
            for ($id = 1; $id <= 7; ++$id) {
                $connection->insert('instance_metric_samples', [
                    'id' => $id,
                    'instance_id' => 123,
                    'cpu_percent' => 12.5,
                    'mem_used_bytes' => 4294967296,
                    'tasks_current' => 4,
                    'collected_at' => sprintf('2026-01-%02d 12:00:00', $id),
                ]);
            }

            $repository->deleteExcessForInstance($instance, 3, 2);
            self::assertSame([5, 6, 7], $connection->fetchFirstColumn('SELECT id FROM instance_metric_samples ORDER BY id'));
            $repository->deleteExcessForInstance($instance, 3, 2);
            self::assertSame([5, 6, 7], $connection->fetchFirstColumn('SELECT id FROM instance_metric_samples ORDER BY id'));
            self::assertSame(1, $repository->deleteOlderThan(new \DateTimeImmutable('2026-01-06')));
            self::assertSame([6, 7], $connection->fetchFirstColumn('SELECT id FROM instance_metric_samples ORDER BY id'));
            $recent = $repository->findRecentForInstanceSince($instance, new \DateTimeImmutable('2026-01-01'), 1, 10);
            self::assertSame(7, $recent[0]['id']);
            self::assertSame(12.5, $recent[0]['cpuPercent']);
            self::assertSame(4294967296, $recent[0]['memUsedBytes']);
            self::assertInstanceOf(\DateTimeImmutable::class, $recent[0]['collectedAt']);
            self::assertSame(2, $repository->fetchAggregateForInstanceSince($instance, new \DateTimeImmutable('2026-01-01'))['count']);
            self::assertCount(2, $repository->findSparklineForInstanceSince($instance, new \DateTimeImmutable('2026-01-01')));
            self::assertSame(12.5, $repository->findLatestByInstances([$instance])[123]['cpu_percent']);
        } finally {
            $manager->close();
            $connection->close();
        }
    }

}
