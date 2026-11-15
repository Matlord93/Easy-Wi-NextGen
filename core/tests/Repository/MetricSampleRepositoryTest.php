<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Repository\MetricSampleRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MetricSampleRepositoryTest extends KernelTestCase
{
    public function testRecentSamplesQueryBuilderUsesLimitAndOmitsJsonPayload(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var MetricSampleRepository $repository */
        $repository = $container->get(MetricSampleRepository::class);

        $agent = new Agent('test-agent', [
            'key_id' => 'k',
            'nonce' => 'n',
            'ciphertext' => 'c',
        ], 'Test Agent');

        $qb = $repository->buildRecentSamplesQueryBuilder($agent, new \DateTimeImmutable('-60 minutes'), 1, 100);

        self::assertSame(100, $qb->getMaxResults());
        self::assertStringContainsString('sample.recordedAt >= :since', $qb->getDQL());
        self::assertStringContainsString('sample.cpuPercent', $qb->getDQL());
        self::assertStringNotContainsString('sample.payload', $qb->getDQL());
    }
    public function testScalarRowsKeepTypesAndOnlySparklineLoadsPayload(): void
    {
        self::bootKernel();
        $configuredManager = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        self::assertInstanceOf(\Doctrine\ORM\EntityManagerInterface::class, $configuredManager);
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $manager = new \Doctrine\ORM\EntityManager($connection, $configuredManager->getConfiguration());
        $connection->executeStatement('CREATE TABLE metric_samples (id INTEGER PRIMARY KEY, agent_id VARCHAR(64), recorded_at DATETIME NOT NULL, cpu_percent REAL, memory_percent REAL, disk_percent REAL, net_bytes_sent BIGINT, net_bytes_recv BIGINT, payload CLOB)');
        $registry = $this->createMock(\Doctrine\Persistence\ManagerRegistry::class);
        $registry->expects(self::once())->method('getManagerForClass')->willReturn($manager);
        $repository = new MetricSampleRepository($registry);
        $agent = $manager->getReference(Agent::class, 'metric-test');
        self::assertInstanceOf(Agent::class, $agent);

        try {
            $connection->insert('metric_samples', [
                'id' => 1,
                'agent_id' => 'metric-test',
                'recorded_at' => '2026-01-02 12:00:00',
                'cpu_percent' => 12.5,
                'net_bytes_sent' => 4294967296,
                'payload' => '{"load":2}',
            ]);
            $since = new \DateTimeImmutable('2026-01-01');
            $recent = $repository->findRecentSamplesForAgentSince($agent, $since, 1, 1);
            self::assertSame(1, $recent[0]['id']);
            self::assertSame(12.5, $recent[0]['cpuPercent']);
            self::assertSame(4294967296, $recent[0]['netBytesSent']);
            self::assertNull($recent[0]['memoryPercent']);
            self::assertArrayNotHasKey('payload', $recent[0]);
            self::assertInstanceOf(\DateTimeImmutable::class, $recent[0]['recordedAt']);
            self::assertSame(['load' => 2], $repository->findSparklineSeriesForAgentSince($agent, $since)[0]['payload']);
            self::assertSame(1, $repository->fetchAggregateSnapshotForAgentSince($agent, $since)['count']);
            self::assertEquals(new \DateTimeImmutable('2026-01-02 12:00:00'), $repository->findLatestRecordedAtForAgent($agent));
        } finally {
            $manager->close();
            $connection->close();
        }
    }

}
