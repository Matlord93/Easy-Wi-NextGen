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
}
