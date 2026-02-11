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
}
