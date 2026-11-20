<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\InstanceMetricSampleRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InstanceMetricSampleRepositoryTest extends KernelTestCase
{
    public function testAdminBrowseConvertsIntegerCustomerIdentifiersWithoutLosingMetrics(): void
    {
        self::bootKernel();
        $configured = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $manager = new \Doctrine\ORM\EntityManager($connection, $configured->getConfiguration());
        $registry = $this->createStub(\Doctrine\Persistence\ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);
        $repository = new InstanceMetricSampleRepository($registry);

        try {
            $connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, email VARCHAR(180))');
            $connection->executeStatement('CREATE TABLE agents (id VARCHAR(64) PRIMARY KEY, name VARCHAR(120))');
            $connection->executeStatement('CREATE TABLE instances (id INTEGER PRIMARY KEY, customer_id INTEGER, node_id VARCHAR(64), cpu_limit INTEGER, ram_limit INTEGER)');
            $connection->executeStatement('CREATE TABLE instance_metric_samples (id INTEGER PRIMARY KEY, instance_id INTEGER, cpu_percent REAL, mem_used_bytes BIGINT, collected_at DATETIME, error_code VARCHAR(120))');
            $connection->insert('users', ['id' => 42, 'email' => 'customer@example.test']);
            $connection->insert('agents', ['id' => 'node-fixture', 'name' => null]);
            $connection->insert('instances', ['id' => 123, 'customer_id' => 42, 'node_id' => 'node-fixture', 'cpu_limit' => 2, 'ram_limit' => 2048]);
            $connection->insert('instance_metric_samples', ['id' => 7, 'instance_id' => 123, 'cpu_percent' => 12.5, 'mem_used_bytes' => 4294967296, 'collected_at' => '2026-01-02 12:00:00', 'error_code' => null]);

            $since = new \DateTimeImmutable('2026-01-01');
            $rows = $repository->findAdminBrowseRows($since, 1, 20, null, null, null);

            self::assertCount(1, $rows);
            self::assertSame('42', $rows[0]['customer_id']);
            self::assertSame('customer@example.test', $rows[0]['customer_email']);
            self::assertSame('node-fixture', $rows[0]['node_id']);
            self::assertNull($rows[0]['node_name']);
            self::assertSame(12.5, $rows[0]['cpu_percent']);
            self::assertSame(4294967296, $rows[0]['mem_used_bytes']);
            self::assertSame(2, $rows[0]['booked_cpu_cores']);
            self::assertInstanceOf(\DateTimeImmutable::class, $rows[0]['collected_at']);
            self::assertEquals($rows, $repository->findAdminBrowseRows($since, 1, 20, 'node-fixture', '42', 123));
            self::assertSame([], $repository->findAdminBrowseRows($since, 1, 20, null, '43', null));
        } finally {
            $manager->close();
            $connection->close();
        }
    }

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
