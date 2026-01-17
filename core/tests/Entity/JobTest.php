<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobTest extends TestCase
{
    public function testTransitionsFollowStateMachine(): void
    {
        $job = new Job('node.provision', ['node' => 'alpha']);

        self::assertSame(JobStatus::Queued, $job->getStatus());

        $job->transitionTo(JobStatus::Running);
        self::assertSame(JobStatus::Running, $job->getStatus());

        $job->transitionTo(JobStatus::Succeeded);
        self::assertSame(JobStatus::Succeeded, $job->getStatus());
    }

    public function testInvalidTransitionThrows(): void
    {
        $job = new Job('node.provision', []);

        $this->expectException(\InvalidArgumentException::class);
        $job->transitionTo(JobStatus::Succeeded);
    }

    public function testLockAndUnlockLifecycle(): void
    {
        $job = new Job('node.provision', []);
        $expiresAt = (new \DateTimeImmutable())->modify('+10 minutes');

        $job->lock('agent-1', 'token-123', $expiresAt);

        self::assertTrue($job->isLocked(new \DateTimeImmutable()));
        self::assertSame('agent-1', $job->getLockedBy());

        $job->unlock('token-123');

        self::assertFalse($job->isLocked(new \DateTimeImmutable()));
        self::assertNull($job->getLockedBy());
    }
}
