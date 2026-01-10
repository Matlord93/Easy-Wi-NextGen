<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Job;
use App\Entity\JobLog;
use Doctrine\ORM\EntityManagerInterface;

final class JobLogger
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function log(Job $job, string $message, ?int $progress = null): JobLog
    {
        $log = new JobLog($job, $message, $progress);
        $this->entityManager->persist($log);
        $job->setProgress($progress);

        return $log;
    }
}
