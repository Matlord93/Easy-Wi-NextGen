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
}
