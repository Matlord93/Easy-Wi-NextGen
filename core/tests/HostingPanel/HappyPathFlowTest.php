<?php

declare(strict_types=1);

namespace App\Tests\HostingPanel;

use App\Module\HostingPanel\Domain\Entity\Agent;
use App\Module\HostingPanel\Domain\Entity\Job;
use App\Module\HostingPanel\Domain\Entity\Node;
use App\Module\HostingPanel\Domain\Enum\JobStatus;
use PHPUnit\Framework\TestCase;

final class HappyPathFlowTest extends TestCase
{
    public function testRegisterHeartbeatDispatchAndStatusReportFlow(): void
    {
        $node = new Node('node-1', 'node-1.example', '10.0.0.10');
        $agent = new Agent($node, 'agent-1', '1.0.0', 'linux', hash('sha256', 'token'));

        $agent->updateHeartbeat('1.1.0', 'linux', ['backups', 'voice']);

        $job = new Job($node, 'backup.create', 'idem-job-1', ['target' => 's3']);
        self::assertSame(JobStatus::Queued, $job->getStatus());

        $job->setStatus(JobStatus::Running);
        $job->setStatus(JobStatus::Success);

        self::assertSame(JobStatus::Success, $job->getStatus());
    }
}
