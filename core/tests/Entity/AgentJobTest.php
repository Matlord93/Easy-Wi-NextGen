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

    public function testExpiredVoiceSnapshotsRemoveRestoreInputAndResultsButPreserveScope(): void
    {
        $agent = new Agent('agent-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        foreach (['ts3.virtual.snapshot.create', 'ts3.virtual.snapshot.restore', 'ts6.virtual.snapshot.create', 'ts6.virtual.snapshot.restore'] as $type) {
            $job = new AgentJob('snapshot-job', $agent, $type, ['node_id' => 7, 'virtual_server_id' => 42, 'snapshot_content' => 'private-snapshot']);
            $job->markRunning();
            $job->markFinished(AgentJobStatus::Success);
            $job->setResultPayload(['snapshot' => 'private-snapshot']);
            $job->setLogText('private-snapshot');
            $job->forgetVoiceSnapshot();
            self::assertSame(['node_id' => 7, 'virtual_server_id' => 42], $job->getPayload());
            self::assertNull($job->getResultPayload());
            self::assertNull($job->getLogText());
            self::assertSame(AgentJobStatus::Success, $job->getStatus());
        }
    }

    public function testSharedHostBackupsCannotBeExpiredAsVirtualSnapshots(): void
    {
        $agent = new Agent('agent-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $job = new AgentJob('backup-job', $agent, 'ts3.backup.create', []);
        $job->markRunning();
        $job->markFinished(AgentJobStatus::Success);
        $this->expectException(\DomainException::class);
        $job->forgetVoiceSnapshot();
    }

    public function testRunningVoiceSnapshotIsNeverExpired(): void
    {
        $agent = new Agent('agent-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $job = new AgentJob('snapshot-job', $agent, 'ts6.virtual.snapshot.restore', ['snapshot_content' => 'needed-by-agent']);
        $job->markRunning();
        $this->expectException(\DomainException::class);
        $job->forgetVoiceSnapshot();
    }
}
