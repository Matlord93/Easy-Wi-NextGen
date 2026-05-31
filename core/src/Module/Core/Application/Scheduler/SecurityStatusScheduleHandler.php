<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Domain\Entity\Job;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;

final class SecurityStatusScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function type(): string
    {
        return 'security.status';
    }

    public function schedules(): array
    {
        return [
            new InternalSchedule('system', 'security.ddos.status', 'DDoS Status Collection', $this->type(), 'core', '*/5 * * * *', true, ['job_type' => 'ddos.status.check']),
            new InternalSchedule('system', 'security.fail2ban.status', 'Fail2ban Status Collection', $this->type(), 'core', '*/10 * * * *', true, ['job_type' => 'fail2ban.status.check']),
            new InternalSchedule('system', 'security.events.collect', 'Security Events Collection', $this->type(), 'core', '*/10 * * * *', true, ['job_type' => 'security.events.collect']),
        ];
    }

    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return $this->queueJobsForAllAgents(['ddos.status.check', 'fail2ban.status.check', 'security.events.collect']);
    }

    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        $jobType = match ($id) {
            'security.ddos.status' => 'ddos.status.check',
            'security.fail2ban.status' => 'fail2ban.status.check',
            'security.events.collect' => 'security.events.collect',
            default => null,
        };

        if ($jobType === null) {
            return ScheduleExecutionResult::failed('Unknown security schedule id: ' . $id);
        }

        return $this->queueJobsForAllAgents([$jobType]);
    }

    private function queueJobsForAllAgents(array $jobTypes): ScheduleExecutionResult
    {
        $agents = $this->agentRepository->findAll();
        if ($agents === []) {
            return ScheduleExecutionResult::skipped('No agents registered.');
        }

        $jobs = [];
        foreach ($agents as $agent) {
            foreach ($jobTypes as $jobType) {
                $job = new Job($jobType, ['agent_id' => $agent->getId()]);
                $this->entityManager->persist($job);
                $jobs[] = $job;
            }
        }

        $this->entityManager->flush();

        $jobIds = array_filter(array_map(fn (Job $j) => $j->getId(), $jobs));

        return ScheduleExecutionResult::success(
            sprintf('Queued %d security status job(s) for %d agent(s).', count($jobs), count($agents)),
            $jobIds,
        );
    }
}
