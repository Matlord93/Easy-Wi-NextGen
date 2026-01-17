<?php

declare(strict_types=1);

namespace App\Extension\Event;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\JobResult;
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
