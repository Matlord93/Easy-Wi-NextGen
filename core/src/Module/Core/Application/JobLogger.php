<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\JobLog;
use Doctrine\ORM\EntityManagerInterface;

final class JobLogger
{
    private const MAX_MESSAGE_LENGTH = 255;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function log(Job $job, string $message, ?int $progress = null): JobLog
    {
        $log = new JobLog($job, $this->truncateMessage($message), $progress);
        $this->entityManager->persist($log);
        $job->setProgress($progress);

        return $log;
    }

    private function truncateMessage(string $message): string
    {
        if (mb_strlen($message) <= self::MAX_MESSAGE_LENGTH) {
            return $message;
        }

        return mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);
    }
}
