<?php

declare(strict_types=1);

namespace App\Message;

final class RunInstanceActionMessage
{
    public function __construct(private readonly string $jobId)
    {
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }
}
