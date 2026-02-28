<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\MetricAggregate;
use PHPUnit\Framework\TestCase;

final class MetricAggregateTest extends TestCase
{
    public function testIngestTracksMinAvgMax(): void
    {
        $agent = new Agent('agent-a', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $aggregate = new MetricAggregate($agent, '1m', new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        $aggregate->ingest(10.0, 20.0, 30.0);
        $aggregate->ingest(50.0, 40.0, 20.0);

        self::assertSame(2, $aggregate->getSampleCount());
        $ref = new \ReflectionClass($aggregate);
        $cpuMin = $ref->getProperty('cpuMin');
        $cpuMin->setAccessible(true);
        $cpuAvg = $ref->getProperty('cpuAvg');
        $cpuAvg->setAccessible(true);
        $cpuMax = $ref->getProperty('cpuMax');
        $cpuMax->setAccessible(true);

        self::assertSame(10.0, $cpuMin->getValue($aggregate));
        self::assertSame(30.0, $cpuAvg->getValue($aggregate));
        self::assertSame(50.0, $cpuMax->getValue($aggregate));
    }
}
