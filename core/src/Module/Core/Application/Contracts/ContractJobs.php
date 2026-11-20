<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Enum\JobStatus;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ContractJobs
{
    public function __construct(private EntityManagerInterface $em, private RentalSettings $settings)
    {
    }

    public function readyAt(ShopContract $contract, bool $stopping = false): ?\DateTimeImmutable
    {
        $id = $stopping ? $contract->getStopJobId() : $contract->getJobId();
        $system = $stopping ? $contract->getStopJobSystem() : $contract->getJobSystem();
        if (null === $id) {
            return null;
        }
        if ('core' === $system) {
            $job = $this->em->find(Job::class, $id);
            if (null !== $job && JobStatus::Succeeded === $job->getStatus()) {
                return $job->getUpdatedAt();
            }
            if (null !== $job && in_array($job->getStatus(), [JobStatus::Failed, JobStatus::Cancelled], true)) {
                $contract->fail($stopping ? 'STOP_FAILED' : 'PROVISION_FAILED');
            }
        } elseif ('agent' === $system) {
            $job = $this->em->find(AgentJob::class, $id);
            if (null !== $job && AgentJobStatus::Success === $job->getStatus()) {
                return $job->getFinishedAt() ?? $job->getCreatedAt();
            }
            if (AgentJobStatus::Failed === $job?->getStatus()) {
                $contract->fail($stopping ? 'STOP_FAILED' : 'PROVISION_FAILED');
            }
        }

        return null;
    }

    public function retryStopIfFailed(ShopContract $contract, \DateTimeImmutable $now): void
    {
        if ($contract->getStopAttempts() >= (int) $this->settings->get()['stop_attempts']) {
            $contract->fail('STOP_RETRY_LIMIT');

            return;
        }
        $cutoff = $now->modify('-'.(int) $this->settings->get()['retry_minutes'].' minutes');
        if ('core' === $contract->getStopJobSystem() && null !== $contract->getStopJobId()) {
            $job = $this->em->find(Job::class, $contract->getStopJobId());
            if (null !== $job && in_array($job->getStatus(), [JobStatus::Failed, JobStatus::Cancelled], true) && $job->getUpdatedAt() <= $cutoff) {
                $contract->retryStop();
            }
        }
        if ('agent' === $contract->getStopJobSystem() && null !== $contract->getStopJobId()) {
            $job = $this->em->find(AgentJob::class, $contract->getStopJobId());
            if (null !== $job && AgentJobStatus::Failed === $job->getStatus() && ($job->getFinishedAt() ?? $job->getCreatedAt()) <= $cutoff) {
                $contract->retryStop();
            }
        }
        foreach ($contract->getStopJobs() as $id) {
            $job = $this->em->find(Job::class, $id);
            if (null !== $job && in_array($job->getStatus(), [JobStatus::Failed, JobStatus::Cancelled], true) && $job->getUpdatedAt() <= $cutoff) {
                $contract->retryStop();
                break;
            }
        }
    }
}
