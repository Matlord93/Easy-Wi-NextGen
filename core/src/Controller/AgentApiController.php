<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\JobResult;
use App\Entity\MetricSample;
use App\Enum\JobResultStatus;
use App\Enum\JobStatus;
use App\Repository\AgentRepository;
use App\Repository\DomainRepository;
use App\Repository\GdprDeletionRequestRepository;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use App\Repository\PublicServerRepository;
use App\Repository\Ts3InstanceRepository;
use App\Repository\UserRepository;
use App\Service\AgentSignatureVerifier;
use App\Service\AuditLogger;
use App\Service\EncryptionService;
use App\Service\FirewallStateManager;
use App\Service\GdprAnonymizer;
use App\Service\NotificationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AgentApiController
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly JobRepository $jobRepository,
        private readonly DomainRepository $domainRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly PublicServerRepository $publicServerRepository,
        private readonly Ts3InstanceRepository $ts3InstanceRepository,
        private readonly UserRepository $userRepository,
        private readonly GdprDeletionRequestRepository $gdprDeletionRequestRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly AgentSignatureVerifier $signatureVerifier,
        private readonly AuditLogger $auditLogger,
        private readonly FirewallStateManager $firewallStateManager,
        private readonly GdprAnonymizer $gdprAnonymizer,
        private readonly NotificationService $notificationService,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[Route(path: '/agent/heartbeat', name: 'agent_heartbeat', methods: ['POST'])]
    public function heartbeat(Request $request): JsonResponse
    {
        $agent = $this->requireAgent($request);

        try {
            $payload = $request->toArray();
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Invalid JSON payload.', $exception);
        }

        $version = (string) ($payload['version'] ?? '');
        $stats = is_array($payload['stats'] ?? null) ? $payload['stats'] : [];
        $roles = is_array($payload['roles'] ?? null) ? $payload['roles'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null;
        $status = isset($payload['status']) ? (string) $payload['status'] : null;
        $ip = $request->getClientIp();

        $agent->recordHeartbeat($stats, $version, $ip, $roles, $metadata, $status);
        $this->entityManager->persist($agent);
        $metricSample = $this->buildMetricSample($agent, $stats['metrics'] ?? null);
        if ($metricSample !== null) {
            $this->entityManager->persist($metricSample);
            $this->auditLogger->log(null, 'agent.metrics_ingested', [
                'agent_id' => $agent->getId(),
                'recorded_at' => $metricSample->getRecordedAt()->format(DATE_RFC3339),
            ]);
        }
        $this->auditLogger->log(null, 'agent.heartbeat', [
            'agent_id' => $agent->getId(),
            'version' => $version,
            'ip' => $ip,
            'roles' => $agent->getRoles(),
            'status' => $agent->getStatus(),
        ]);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route(path: '/agent/jobs', name: 'agent_jobs', methods: ['GET'])]
    public function jobs(Request $request): JsonResponse
    {
        $agent = $this->requireAgent($request);
        $now = new DateTimeImmutable();

        $jobs = $this->jobRepository->findQueuedForDispatch(20);
        $jobPayloads = [];
        $updateJobTypes = ['sniper.update'];
        $maxUpdateJobsPerAgent = 2;
        $runningUpdateJobs = $this->jobRepository->countRunningByAgentAndTypes($agent->getId(), $updateJobTypes);

        foreach ($jobs as $job) {
            if ($job->getStatus() !== JobStatus::Queued) {
                continue;
            }

            $payload = $job->getPayload();
            $targetAgentId = is_string($payload['agent_id'] ?? null) ? $payload['agent_id'] : '';
            if ($targetAgentId !== '' && $targetAgentId !== $agent->getId()) {
                continue;
            }

            if (in_array($job->getType(), $updateJobTypes, true) && $runningUpdateJobs >= $maxUpdateJobsPerAgent) {
                continue;
            }

            $lockToken = bin2hex(random_bytes(16));
            $job->lock($agent->getId(), $lockToken, $now->modify('+10 minutes'));
            $job->transitionTo(JobStatus::Running);

            $this->eventDispatcher->dispatch(
                new \App\Extension\Event\JobBeforeDispatchEvent($job, $agent),
                'extension.job.before_dispatch',
            );

            $jobPayloads[] = [
                'id' => $job->getId(),
                'type' => $job->getType(),
                'payload' => $payload,
                'created_at' => $job->getCreatedAt()->format(DATE_RFC3339),
            ];

            $this->auditLogger->log(null, 'agent.job_assigned', [
                'agent_id' => $agent->getId(),
                'job_id' => $job->getId(),
            ]);

            if (in_array($job->getType(), $updateJobTypes, true)) {
                $runningUpdateJobs++;
            }
        }

        $this->entityManager->flush();

        return new JsonResponse(['jobs' => $jobPayloads]);
    }

    #[Route(path: '/agent/jobs/{id}/result', name: 'agent_job_result', methods: ['POST'])]
    public function jobResult(Request $request, string $id): JsonResponse
    {
        $agent = $this->requireAgent($request);
        $job = $this->jobRepository->find($id);

        if ($job === null) {
            throw new NotFoundHttpException('Job not found.');
        }

        if ($job->getStatus() !== JobStatus::Running) {
            throw new ConflictHttpException('Job is not running.');
        }

        if ($job->getLockedBy() !== $agent->getId()) {
            throw new ConflictHttpException('Job is not locked by this agent.');
        }

        try {
            $payload = $request->toArray();
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Invalid JSON payload.', $exception);
        }

        $jobId = (string) ($payload['job_id'] ?? '');
        if ($jobId !== $job->getId()) {
            throw new BadRequestHttpException('Job id does not match.');
        }

        $status = (string) ($payload['status'] ?? '');
        $resultStatus = match ($status) {
            'success' => JobResultStatus::Succeeded,
            'succeeded' => JobResultStatus::Succeeded,
            'failed' => JobResultStatus::Failed,
            'cancelled' => JobResultStatus::Cancelled,
            default => throw new BadRequestHttpException('Invalid job status.'),
        };

        $completedAt = $this->parseCompletedAt($payload['completed_at'] ?? null);
        $output = is_array($payload['output'] ?? null) ? $payload['output'] : [];

        $jobResult = new JobResult($job, $resultStatus, $output, $completedAt);
        $job->transitionTo(match ($resultStatus) {
            JobResultStatus::Succeeded => JobStatus::Succeeded,
            JobResultStatus::Failed => JobStatus::Failed,
            JobResultStatus::Cancelled => JobStatus::Cancelled,
        });

        $lockToken = $job->getLockToken();
        if ($lockToken !== null) {
            $job->unlock($lockToken);
        }

        $this->entityManager->persist($jobResult);
        $this->applyDomainUpdatesFromJob($job, $resultStatus, $agent->getId(), $output);
        if ($resultStatus === JobResultStatus::Succeeded) {
            $ports = $this->firewallStateManager->portsFromJob($job);
            if ($job->getType() === 'firewall.open_ports') {
                $this->firewallStateManager->applyOpenPorts($agent, $ports);
            }
            if ($job->getType() === 'firewall.close_ports') {
                $this->firewallStateManager->applyClosePorts($agent, $ports);
            }
        }
        $this->applyTs3UpdatesFromJob($job, $resultStatus, $agent->getId(), $output);
        $this->applyInstanceUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $completedAt);
        $this->applyPublicServerUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $completedAt);
        $this->applyGdprAnonymizationFromJob($job, $resultStatus, $agent->getId());
        $this->eventDispatcher->dispatch(
            new \App\Extension\Event\JobAfterResultEvent($job, $jobResult, $agent),
            'extension.job.after_result',
        );
        $this->auditLogger->log(null, 'agent.job_completed', [
            'agent_id' => $agent->getId(),
            'job_id' => $job->getId(),
            'status' => $resultStatus->value,
        ]);
        $this->notificationService->notifyAdmins(
            sprintf('job.completed.%s', $job->getId()),
            sprintf('Job %s completed', $job->getId()),
            sprintf('%s · %s', $job->getType(), $resultStatus->value),
            'jobs',
            '/admin/jobs',
        );
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok']);
    }

    private function requireAgent(Request $request): \App\Entity\Agent
    {
        $agentId = (string) $request->headers->get('X-Agent-ID', '');
        if ($agentId === '') {
            throw new UnauthorizedHttpException('hmac', 'Missing agent id.');
        }

        $agent = $this->agentRepository->find($agentId);
        if ($agent === null) {
            throw new UnauthorizedHttpException('hmac', 'Unknown agent.');
        }

        $secret = $this->encryptionService->decrypt($agent->getSecretPayload());
        $this->signatureVerifier->verify($request, $agentId, $secret);

        return $agent;
    }

    private function parseCompletedAt(mixed $value): DateTimeImmutable
    {
        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (\Exception) {
                throw new BadRequestHttpException('Invalid completed_at timestamp.');
            }
        }

        return new DateTimeImmutable();
    }

    private function buildMetricSample(\App\Entity\Agent $agent, mixed $metrics): ?MetricSample
    {
        if (!is_array($metrics)) {
            return null;
        }

        $recordedAt = $this->parseMetricTimestamp($metrics['collected_at'] ?? null);
        $cpuPercent = $this->parseMetricPercent($metrics, 'cpu');
        $memoryPercent = $this->parseMetricPercent($metrics, 'memory');
        $diskPercent = $this->parseMetricPercent($metrics, 'disk');

        $netBytesSent = $this->parseMetricInt($metrics, ['net', 'bytes_sent']);
        $netBytesRecv = $this->parseMetricInt($metrics, ['net', 'bytes_recv']);

        return new MetricSample(
            $agent,
            $recordedAt,
            $cpuPercent,
            $memoryPercent,
            $diskPercent,
            $netBytesSent,
            $netBytesRecv,
            $metrics,
        );
    }

    private function parseMetricTimestamp(mixed $value): \DateTimeImmutable
    {
        if (is_string($value) && $value !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
            }
        }

        return new \DateTimeImmutable();
    }

    private function parseMetricPercent(array $metrics, string $key): ?float
    {
        $value = $metrics[$key]['percent'] ?? null;
        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function parseMetricInt(array $metrics, array $path): ?int
    {
        $cursor = $metrics;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        if (!is_numeric($cursor)) {
            return null;
        }

        return (int) $cursor;
    }

    private function applyDomainUpdatesFromJob(\App\Entity\Job $job, JobResultStatus $resultStatus, string $agentId, array $output): void
    {
        $payload = $job->getPayload();
        $domainId = $payload['domain_id'] ?? null;
        if (!is_int($domainId) && !is_string($domainId)) {
            return;
        }

        $domain = $this->domainRepository->find((int) $domainId);
        if ($domain === null) {
            return;
        }

        if ($job->getType() === 'domain.add') {
            $status = match ($resultStatus) {
                JobResultStatus::Succeeded => 'active',
                JobResultStatus::Failed => 'failed',
                JobResultStatus::Cancelled => 'cancelled',
            };

            $previousStatus = $domain->getStatus();
            $domain->setStatus($status);
            $this->entityManager->persist($domain);
            $this->auditLogger->log(null, 'domain.status_updated', [
                'domain_id' => $domain->getId(),
                'job_id' => $job->getId(),
                'agent_id' => $agentId,
                'previous_status' => $previousStatus,
                'status' => $status,
            ]);
            return;
        }

        if ($job->getType() !== 'domain.ssl.issue') {
            return;
        }

        if ($resultStatus !== JobResultStatus::Succeeded) {
            return;
        }

        $expiresAt = $this->parseSslExpiry($output['expires_at'] ?? null);
        if ($expiresAt === null) {
            return;
        }

        $previousExpiry = $domain->getSslExpiresAt();
        $domain->setSslExpiresAt($expiresAt);
        $this->entityManager->persist($domain);
        $this->auditLogger->log(null, 'domain.ssl_issued', [
            'domain_id' => $domain->getId(),
            'job_id' => $job->getId(),
            'agent_id' => $agentId,
            'ssl_expires_at' => $expiresAt->format(DATE_RFC3339),
            'previous_ssl_expires_at' => $previousExpiry?->format(DATE_RFC3339),
            'cert_path' => is_string($output['cert_path'] ?? null) ? $output['cert_path'] : null,
            'fullchain_path' => is_string($output['fullchain_path'] ?? null) ? $output['fullchain_path'] : null,
        ]);
    }

    private function applyTs3UpdatesFromJob(\App\Entity\Job $job, JobResultStatus $resultStatus, string $agentId, array $output): void
    {
        $payload = $job->getPayload();
        $instanceId = $payload['ts3_instance_id'] ?? $payload['instance_id'] ?? null;
        if (!is_int($instanceId) && !is_string($instanceId)) {
            return;
        }

        $instance = $this->ts3InstanceRepository->find((int) $instanceId);
        if ($instance === null) {
            return;
        }

        $newStatus = null;
        if ($resultStatus === JobResultStatus::Failed) {
            $newStatus = \App\Enum\Ts3InstanceStatus::Error;
        } elseif ($resultStatus === JobResultStatus::Succeeded) {
            $newStatus = match ($job->getType()) {
                'ts3.create', 'ts3.start', 'ts3.restart', 'ts3.update', 'ts3.restore' => \App\Enum\Ts3InstanceStatus::Running,
                'ts3.stop' => \App\Enum\Ts3InstanceStatus::Stopped,
                default => null,
            };
        }

        if ($newStatus === null || $instance->getStatus() === $newStatus) {
            return;
        }

        $previousStatus = $instance->getStatus()->value;
        $instance->setStatus($newStatus);
        $this->entityManager->persist($instance);
        $this->auditLogger->log(null, 'ts3.instance_status_updated', [
            'instance_id' => $instance->getId(),
            'job_id' => $job->getId(),
            'agent_id' => $agentId,
            'previous_status' => $previousStatus,
            'status' => $newStatus->value,
            'output' => $output,
        ]);
    }

    private function applyInstanceUpdatesFromJob(
        \App\Entity\Job $job,
        JobResultStatus $resultStatus,
        string $agentId,
        array $output,
        DateTimeImmutable $completedAt,
    ): void {
        if ($resultStatus !== JobResultStatus::Succeeded) {
            return;
        }

        if (!in_array($job->getType(), ['sniper.install', 'sniper.update'], true)) {
            return;
        }

        $payload = $job->getPayload();
        $instanceId = $payload['instance_id'] ?? null;
        if (!is_int($instanceId) && !is_string($instanceId)) {
            return;
        }

        $instance = $this->instanceRepository->find((int) $instanceId);
        if ($instance === null) {
            return;
        }

        if ($instance->getNode()->getId() !== $agentId) {
            return;
        }

        $buildId = is_string($output['build_id'] ?? null) ? (string) $output['build_id'] : null;
        $version = is_string($output['version'] ?? null) ? (string) $output['version'] : null;

        if ($buildId === null && $version === null) {
            return;
        }

        $instance->setPreviousBuildId($instance->getCurrentBuildId());
        $instance->setPreviousVersion($instance->getCurrentVersion());
        $instance->setCurrentBuildId($buildId);
        $instance->setCurrentVersion($version);
        $this->entityManager->persist($instance);

        $this->auditLogger->log(null, 'instance.build.updated', [
            'instance_id' => $instance->getId(),
            'job_id' => $job->getId(),
            'build_id' => $buildId,
            'version' => $version,
            'completed_at' => $completedAt->format(DATE_RFC3339),
        ]);
    }

    private function parseSslExpiry(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function applyPublicServerUpdatesFromJob(
        \App\Entity\Job $job,
        JobResultStatus $resultStatus,
        string $agentId,
        array $output,
        DateTimeImmutable $completedAt,
    ): void {
        if ($job->getType() !== 'server.status.check') {
            return;
        }

        $payload = $job->getPayload();
        $serverId = $payload['server_id'] ?? null;
        if (!is_int($serverId) && !is_string($serverId)) {
            return;
        }

        $server = $this->publicServerRepository->find((int) $serverId);
        if ($server === null) {
            return;
        }

        $status = match ($resultStatus) {
            JobResultStatus::Succeeded => is_string($output['status'] ?? null) ? strtolower((string) $output['status']) : 'online',
            JobResultStatus::Failed => 'error',
            JobResultStatus::Cancelled => 'unknown',
        };

        $statusCache = $server->getStatusCache();
        $statusCache['status'] = $status;
        $statusCache['players'] = is_numeric($output['players'] ?? null) ? (int) $output['players'] : null;
        $statusCache['max_players'] = is_numeric($output['max_players'] ?? null) ? (int) $output['max_players'] : null;
        $statusCache['map'] = is_string($output['map'] ?? null) ? $output['map'] : null;
        $statusCache['checked_at'] = $completedAt->format(DATE_RFC3339);

        $server->setStatusCache($statusCache);
        $server->setLastCheckedAt($completedAt);
        $this->entityManager->persist($server);

        $this->auditLogger->log(null, 'public_server.status_checked', [
            'server_id' => $server->getId(),
            'job_id' => $job->getId(),
            'agent_id' => $agentId,
            'status' => $status,
        ]);
    }

    private function applyGdprAnonymizationFromJob(\App\Entity\Job $job, JobResultStatus $resultStatus, string $agentId): void
    {
        if ($job->getType() !== 'gdpr.anonymize_user') {
            return;
        }

        if ($resultStatus !== JobResultStatus::Succeeded) {
            return;
        }

        $payload = $job->getPayload();
        $userId = $payload['user_id'] ?? null;
        if (!is_int($userId) && !is_string($userId)) {
            return;
        }

        $user = $this->userRepository->find((int) $userId);
        if ($user === null) {
            return;
        }

        $this->gdprAnonymizer->anonymize($user);

        $request = $this->gdprDeletionRequestRepository->findByJobId($job->getId());
        if ($request !== null) {
            $request->markCompleted();
            $this->entityManager->persist($request);
        }

        $this->auditLogger->log(null, 'gdpr.user_anonymized', [
            'user_id' => $user->getId(),
            'job_id' => $job->getId(),
            'agent_id' => $agentId,
        ]);
    }
}
