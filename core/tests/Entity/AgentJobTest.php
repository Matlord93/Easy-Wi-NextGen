<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Domain\Entity\Agent;
use PHPUnit\Framework\TestCase;

final class AgentJobTest extends TestCase
{
    public function testLifecycleTransitions(): void
    {
        $agent = new Agent('agent-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'Node');
        $job = new AgentJob('job-1', $agent, 'ts3.install', ['install_dir' => '/srv/ts3']);

        self::assertSame(AgentJobStatus::Queued, $job->getStatus());

        $job->markRunning();
        self::assertSame(AgentJobStatus::Running, $job->getStatus());
        self::assertNotNull($job->getStartedAt());

        $job->markFinished(AgentJobStatus::Success);
        self::assertSame(AgentJobStatus::Success, $job->getStatus());
        self::assertNotNull($job->getFinishedAt());
    }
}
