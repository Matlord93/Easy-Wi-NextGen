<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Application\Job;

use App\Module\HostingPanel\Domain\Enum\JobStatus;

class JobStateMachine
{
    public function canTransition(JobStatus $from, JobStatus $to): bool
    {
        return match ($from) {
            JobStatus::Queued => in_array($to, [JobStatus::Running, JobStatus::Cancelled], true),
            JobStatus::Running => in_array($to, [JobStatus::Success, JobStatus::Failed, JobStatus::Retry], true),
            JobStatus::Retry => $to === JobStatus::Queued,
            default => false,
        };
    }
}
