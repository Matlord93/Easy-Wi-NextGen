<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\AgentMetricsIngestionService;
use App\Module\Core\Domain\Entity\Agent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AgentMetricsIngestionServiceTest extends KernelTestCase
{
    public function testResolveStatusOffline(): void
    {
        self::bootKernel();
        $service = static::getContainer()->get(AgentMetricsIngestionService::class);

        $agent = new Agent('agent-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        self::assertSame('offline', $service->resolveStatus($agent, 60));
    }

    public function testResolveStatusCriticalByThreshold(): void
    {
        self::bootKernel();
        $service = static::getContainer()->get(AgentMetricsIngestionService::class);

        $agent = new Agent('agent-2', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $agent->recordHeartbeat(['metrics' => ['cpu' => ['percent' => 97]]], '1.0.0', '127.0.0.1');

        self::assertSame('critical', $service->resolveStatus($agent, 3600));
    }
}
