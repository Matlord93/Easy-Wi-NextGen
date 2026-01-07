<?php

declare(strict_types=1);

namespace App\Extension\Event;

use App\Entity\Agent;
use App\Entity\Job;
use App\Entity\JobResult;
use Symfony\Contracts\EventDispatcher\Event;

final class JobAfterResultEvent extends Event
{
    public function __construct(
        public readonly Job $job,
        public readonly JobResult $jobResult,
        public readonly Agent $agent,
    ) {
    }
}
