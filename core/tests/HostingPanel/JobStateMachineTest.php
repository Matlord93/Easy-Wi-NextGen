<?php

declare(strict_types=1);

namespace App\Tests\HostingPanel;

use App\Module\HostingPanel\Application\Job\JobStateMachine;
use App\Module\HostingPanel\Domain\Enum\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobStateMachineTest extends TestCase
{
    public function testTransitions(): void
    {
        $sm = new JobStateMachine();

        self::assertTrue($sm->canTransition(JobStatus::Queued, JobStatus::Running));
        self::assertTrue($sm->canTransition(JobStatus::Running, JobStatus::Retry));
        self::assertFalse($sm->canTransition(JobStatus::Success, JobStatus::Running));
    }
}
