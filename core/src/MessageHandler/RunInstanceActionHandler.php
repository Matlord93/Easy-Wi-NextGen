<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RunInstanceActionMessage;
use App\Repository\JobRepository;
use App\Service\JobLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunInstanceActionHandler
{
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly JobLogger $jobLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RunInstanceActionMessage $message): void
    {
        $job = $this->jobRepository->find($message->getJobId());
        if ($job === null) {
            throw new \RuntimeException('Job not found.');
        }

        if ($job->getStatus()->isTerminal()) {
            return;
        }

        $this->jobLogger->log($job, 'Job dispatched to agent queue.', 5);
        $this->entityManager->flush();
    }
}
