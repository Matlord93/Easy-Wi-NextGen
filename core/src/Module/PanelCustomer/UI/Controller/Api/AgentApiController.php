<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Api;

use App\Module\Core\Application\AgentSignatureVerifier;
use App\Module\Core\Application\AgentMetricsIngestionService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\FirewallStateManager;
use App\Module\Core\Application\GdprAnonymizer;
use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Core\Application\JobLogger;
use App\Module\Core\Application\NodeDiskProtectionService;
use App\Module\Core\Application\NotificationService;
use App\Module\Core\Domain\Entity\DdosPolicy;
use App\Module\Core\Domain\Entity\DdosStatus;
use App\Module\Core\Domain\Entity\Certificate;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceMetricSample;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\JobResult;
use App\Module\Core\Domain\Entity\MetricSample;
use App\Module\Core\Domain\Entity\SecurityEvent;
use App\Module\Core\Domain\Enum\BackupStatus;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\JobResultStatus;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Module\Gameserver\Application\GameServerPathResolver;
use App\Module\Gameserver\Application\Query\QueryResultNormalizer;
use App\Repository\AgentRepository;
use App\Repository\BackupDefinitionRepository;
use App\Repository\BackupRepository;
use App\Repository\BackupTargetRepository;
use App\Repository\DatabaseRepository;
use App\Repository\DdosPolicyRepository;
use App\Repository\DdosStatusRepository;
use App\Repository\DomainRepository;
use App\Repository\GdprDeletionRequestRepository;
use App\Repository\InstanceMetricSampleRepository;
use App\Repository\InstanceRepository;
use App\Repository\InstanceSftpCredentialRepository;
use App\Repository\JobRepository;
use App\Repository\PublicServerRepository;
use App\Repository\SecurityPolicyRevisionRepository;
use App\Repository\Ts3InstanceRepository;
use App\Repository\Ts6InstanceRepository;
use App\Repository\UserRepository;
use App\Repository\VoiceInstanceRepository;
use App\Repository\WebspaceRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class AgentApiController
{
    /**
     * Jobs that are explicitly supported on Windows agents.
     *
     * @var array<int, string>
     */
    private const WINDOWS_ALLOWED_JOB_TYPES = [
        'agent.update',
        'agent.self_update',
        'agent.diagnostics',
        'role.ensure_base',
        'security.ensure_base',
        'web.ensure_base',
        'instance.config.apply',
        'instance.sftp.credentials.reset',
        'windows.service.start',
        'windows.service.stop',
        'windows.service.restart',
    ];

    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly JobRepository $jobRepository,
        private readonly DomainRepository $domainRepository,
        private readonly DatabaseRepository $databaseRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceMetricSampleRepository $instanceMetricSampleRepository,
        private readonly InstanceSftpCredentialRepository $instanceSftpCredentialRepository,
        private readonly PublicServerRepository $publicServerRepository,
        private readonly Ts3InstanceRepository $ts3InstanceRepository,
        private readonly Ts6InstanceRepository $ts6InstanceRepository,
        private readonly UserRepository $userRepository,
        private readonly VoiceInstanceRepository $voiceInstanceRepository,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly GdprDeletionRequestRepository $gdprDeletionRequestRepository,
        private readonly BackupDefinitionRepository $backupDefinitionRepository,
        private readonly BackupRepository $backupRepository,
        private readonly BackupTargetRepository $backupTargetRepository,
        private readonly DdosPolicyRepository $ddosPolicyRepository,
        private readonly DdosStatusRepository $ddosStatusRepository,
        private readonly SecurityPolicyRevisionRepository $policyRevisionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly AgentSignatureVerifier $signatureVerifier,
        private readonly AuditLogger $auditLogger,
        private readonly AgentMetricsIngestionService $metricsIngestionService,
        private readonly FirewallStateManager $firewallStateManager,
        private readonly GdprAnonymizer $gdprAnonymizer,
        private readonly NotificationService $notificationService,
        private readonly NodeDiskProtectionService $nodeDiskProtectionService,
        private readonly object $filesystemResolver,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly JobLogger $jobLogger,
        private readonly LoggerInterface $logger,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%app.windows_nodes_enabled%')]
        private readonly bool $windowsNodesEnabled,
    ) {
    }

    #[Route(path: '/agent/heartbeat', name: 'agent_heartbeat', methods: ['POST'])]
    #[Route(path: '/api/v1/agent/heartbeat', name: 'agent_heartbeat_v1', methods: ['POST'])]
    public function heartbeat(Request $request): JsonResponse
    {
        $agent = $this->requireAgent($request);
        if ($agent instanceof JsonResponse) {
            return $agent;
        }

        if ($this->isDecommissionedAgent($agent)) {
            $this->auditLogger->log(null, 'agent.heartbeat_rejected', [
                'agent_id' => $agent->getId(),
                'reason' => 'agent_decommissioned',
            ]);

            return new JsonResponse([
                'error' => 'agent_decommissioned',
            ], JsonResponse::HTTP_FORBIDDEN);
        }

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
        $ip = $this->resolveAgentIpFromRequest($request);

        if ($this->isWindowsStats($stats) && !$this->windowsNodesEnabled) {
            throw new ServiceUnavailableHttpException(null, 'Windows nodes are currently disabled.');
        }

        $agent->recordHeartbeat($stats, $version, $ip, $roles, $metadata, $status);
        $this->entityManager->persist($agent);
        $ingested = $this->metricsIngestionService->ingestBatch($agent, [is_array($stats['metrics'] ?? null) ? $stats['metrics'] : []]);
        if ($ingested > 0) {
            $this->auditLogger->log(null, 'agent.metrics_ingested', [
                'agent_id' => $agent->getId(),
                'ingested' => $ingested,
            ]);
        }
        $this->ingestInstanceMetrics($stats['metrics']['instance_metrics'] ?? null);
        $agent->setStatus($this->metricsIngestionService->resolveStatus($agent, 120));
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

    private function resolveAgentIpFromRequest(Request $request): ?string
    {
        $forwardedFor = trim((string) $request->headers->get('X-Forwarded-For'));
        if ($forwardedFor !== '') {
            foreach (explode(',', $forwardedFor) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        $realIp = trim((string) $request->headers->get('X-Real-IP'));
        if ($realIp !== '') {
            return $realIp;
        }

        $ip = trim((string) $request->getClientIp());
        return $ip !== '' ? $ip : null;
    }


    #[Route(path: '/agent/metrics-batch', name: 'agent_metrics_batch', methods: ['POST'])]
    public function ingestMetricsBatch(Request $request): JsonResponse
    {
        $agent = $this->requireAgent($request);
        if ($agent instanceof JsonResponse) {
            return $agent;
        }

        if ($this->isDecommissionedAgent($agent)) {
            return new JsonResponse(['error' => 'agent_decommissioned'], JsonResponse::HTTP_FORBIDDEN);
        }

        $raw = (string) $request->getContent();
        if (strtolower((string) $request->headers->get('Content-Encoding')) === 'gzip') {
            $decoded = @gzdecode($raw);
            if (!is_string($decoded)) {
                throw new BadRequestHttpException('Invalid gzip payload.');
            }
            $raw = $decoded;
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Invalid JSON payload.', $exception);
        }

        $samples = is_array($payload['samples'] ?? null) ? $payload['samples'] : [];
        if (count($samples) > 1000) {
            $samples = array_slice($samples, 0, 1000);
        }

        $ingested = $this->metricsIngestionService->ingestBatch($agent, array_values(array_filter($samples, static fn ($v): bool => is_array($v))));
        $this->auditLogger->log(null, 'agent.metrics_batch_ingested', ['agent_id' => $agent->getId(), 'ingested' => $ingested]);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok', 'ingested' => $ingested]);
    }


    #[Route(path: '/agent/jobs', name: 'agent_jobs', methods: ['GET'])]
    #[Route(path: '/api/v1/agent/jobs', name: 'agent_jobs_v1', methods: ['GET'])]
    public function jobs(Request $request): JsonResponse
    {
        $agent = $this->requireAgent($request);
        if ($agent instanceof JsonResponse) {
            return $agent;
        }

        if ($this->isDecommissionedAgent($agent)) {
            $cancelled = $this->cancelPendingJobsForDecommissionedAgent($agent, 'dispatch');

            return new JsonResponse([
                'error' => 'agent_decommissioned',
                'cancelled_jobs' => $cancelled,
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        $now = new DateTimeImmutable();
        $this->expireStaleJobs($now);

        $jobs = $this->jobRepository->findQueuedForDispatch(20);
        $totalQueued = count($jobs);
        $jobPayloads = [];
        $updateJobTypes = ['sniper.update', 'agent.update', 'agent.self_update'];
        $nonBlockingJobTypes = ['instance.logs.tail', 'webspace.logs.tail'];
        $maxUpdateJobsPerAgent = 2;
        $runningUpdateJobs = $this->jobRepository->countRunningByAgentAndTypes($agent->getId(), $updateJobTypes);
        $maxConcurrency = $agent->getJobConcurrency();
        $runningJobs = $this->jobRepository->countRunningByAgentExcludingTypes($agent->getId(), $nonBlockingJobTypes);
        $availableSlots = max(0, $maxConcurrency - $runningJobs);
        $isWindowsAgent = $this->isWindowsAgent($agent);
        $backoffSeconds = 30;
        $rejections = [
            'not_queued' => 0,
            'agent_mismatch' => 0,
            'slots_full' => 0,
            'update_limit' => 0,
            'windows_unsupported' => 0,
            'backoff' => 0,
        ];

        if ($isWindowsAgent && !$this->windowsNodesEnabled) {
            throw new ServiceUnavailableHttpException(null, 'Windows nodes are currently disabled.');
        }

        if ($availableSlots === 0) {
            $this->logger->info('agent.jobs.poll', [
                'agent_id' => $agent->getId(),
                'queued_jobs' => $totalQueued,
                'available_slots' => 0,
                'rejections' => $rejections,
            ]);
            return new JsonResponse([
                'jobs' => [],
                'max_concurrency' => $maxConcurrency,
            ]);
        }

        $dispatched = 0;
        foreach ($jobs as $job) {
            if ($dispatched >= $availableSlots) {
                $rejections['slots_full'] += max(0, $totalQueued - $dispatched);
                break;
            }
            if ($job->getStatus() !== JobStatus::Queued) {
                $rejections['not_queued']++;
                continue;
            }

            if ($job->getAttempts() > 0 && $job->getLastAttemptAt() !== null) {
                $retryAt = $job->getLastAttemptAt()->modify(sprintf('+%d seconds', $backoffSeconds));
                if ($retryAt > $now) {
                    $rejections['backoff']++;
                    continue;
                }
            }

            $payload = $job->getPayload();
            $payload = $this->resolveBackupTargetPayload($job->getType(), $payload);
            $targetAgentId = is_string($payload['agent_id'] ?? null) ? $payload['agent_id'] : '';
            if ($targetAgentId !== '' && $targetAgentId !== $agent->getId()) {
                $rejections['agent_mismatch']++;
                continue;
            }

            if (in_array($job->getType(), $updateJobTypes, true) && $runningUpdateJobs >= $maxUpdateJobsPerAgent) {
                $rejections['update_limit']++;
                continue;
            }

            if (!$this->canDispatchJobToAgent($job->getType(), $agent)) {
                $rejections['windows_unsupported']++;
                continue;
            }

            $lockToken = bin2hex(random_bytes(16));
            $job->lock($agent->getId(), $lockToken, $now->modify('+10 minutes'));
            $job->claim($agent->getId(), $now);
            $job->transitionTo(JobStatus::Claimed);
            $this->jobLogger->log($job, 'Job claimed.', 5);

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

            $dispatched++;
        }

        $this->entityManager->flush();

        $this->logger->info('agent.jobs.poll', [
            'agent_id' => $agent->getId(),
            'queued_jobs' => $totalQueued,
            'assigned_jobs' => $dispatched,
            'available_slots' => $availableSlots,
            'rejections' => $rejections,
        ]);

        return new JsonResponse([
            'jobs' => $jobPayloads,
            'max_concurrency' => $maxConcurrency,
        ]);
    }

    #[Route(path: '/agent/jobs/{id}/start', name: 'agent_job_start', methods: ['POST'])]
    #[Route(path: '/api/v1/agent/jobs/{id}/start', name: 'agent_job_start_v1', methods: ['POST'])]
    public function jobStart(Request $request, string $id): JsonResponse
    {
        $agent = $this->requireAgent($request);
        if ($agent instanceof JsonResponse) {
            return $agent;
        }
        $job = $this->jobRepository->find($id);

        if ($job === null) {
            throw new NotFoundHttpException('Job not found.');
        }

        if ($this->isDecommissionedAgent($agent)) {
            $this->markJobFailedForDecommissionedAgent($job, $agent, 'start');

            return new JsonResponse(['error' => 'agent_decommissioned'], JsonResponse::HTTP_FORBIDDEN);
        }

        if ($job->getStatus()->isTerminal()) {
            if ($job->getClaimedBy() !== $agent->getId()) {
                throw new ConflictHttpException('Job is already completed by another agent.');
            }

            return new JsonResponse(['status' => 'ok']);
        }

        if (!in_array($job->getStatus(), [JobStatus::Claimed, JobStatus::Running], true)) {
            throw new ConflictHttpException('Job is not claimed.');
        }

        if ($job->getLockedBy() !== $agent->getId()) {
            throw new ConflictHttpException('Job is not locked by this agent.');
        }

        if ($job->getStatus() === JobStatus::Claimed) {
            $job->transitionTo(JobStatus::Running);
            $this->jobLogger->log($job, 'Job started.', 10);
            $this->entityManager->flush();
        }

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route(path: '/agent/jobs/{id}/result', name: 'agent_job_result', methods: ['POST'])]
    #[Route(path: '/api/v1/agent/jobs/{id}/result', name: 'agent_job_result_v1', methods: ['POST'])]
    public function jobResult(Request $request, string $id): JsonResponse
    {
        $agent = $this->requireAgent($request);
        if ($agent instanceof JsonResponse) {
            return $agent;
        }
        $job = $this->jobRepository->find($id);

        if ($job === null) {
            throw new NotFoundHttpException('Job not found.');
        }

        if ($this->isDecommissionedAgent($agent)) {
            $this->markJobFailedForDecommissionedAgent($job, $agent, 'result');

            return new JsonResponse(['error' => 'agent_decommissioned'], JsonResponse::HTTP_FORBIDDEN);
        }

        if (!in_array($job->getStatus(), [JobStatus::Claimed, JobStatus::Running], true)) {
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
        $databaseCredential = is_string($output['one_time_credential'] ?? null)
            ? trim((string) $output['one_time_credential'])
            : null;
        if ($databaseCredential === '') {
            $databaseCredential = null;
        }
        unset($output['one_time_credential']);

        $this->applyInstanceSftpCredentialUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $completedAt);
        $this->appendJobLogsFromOutput($job, $output);

        $jobResult = new JobResult($job, $resultStatus, $output, $completedAt);
        $this->applyJobFailureContext($job, $resultStatus, $output);
        $job->transitionTo(match ($resultStatus) {
            JobResultStatus::Succeeded => JobStatus::Succeeded,
            JobResultStatus::Failed => JobStatus::Failed,
            JobResultStatus::Cancelled => JobStatus::Cancelled,
        });
        $this->jobLogger->log($job, sprintf('Job %s.', $resultStatus->value), 100);

        $lockToken = $job->getLockToken();
        if ($lockToken !== null) {
            $job->unlock($lockToken);
        }
        $job->clearClaim();

        $this->entityManager->persist($jobResult);
        $this->applyDomainUpdatesFromJob($job, $resultStatus, $agent->getId(), $output);
        $this->applyDatabaseUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $databaseCredential);
        if ($resultStatus === JobResultStatus::Succeeded) {
            $this->firewallStateManager->applyFirewallJobResult($job, $agent, $output);
        }
        $this->applyDdosStatusFromJob($job, $resultStatus, $agent, $output, $completedAt);
        $this->applyDdosPolicyFromJob($job, $resultStatus, $agent, $output, $completedAt);
        $this->applyFail2banStatusFromJob($job, $resultStatus, $agent, $output, $completedAt);
        $this->applyFail2banPolicyFromJob($job, $resultStatus, $agent, $output, $completedAt);
        $this->applySecurityEventsFromJob($job, $resultStatus, $agent, $output, $completedAt);
        $this->applyPolicyRevisionStatusFromJob($job, $resultStatus, $completedAt);
        $this->applyTs3UpdatesFromJob($job, $resultStatus, $agent->getId(), $output);
        $this->applyTs6UpdatesFromJob($job, $resultStatus, $agent->getId(), $output);
        $this->applyInstanceUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $completedAt);
        $this->applyDiskUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $completedAt);
        $this->applyPublicServerUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $completedAt);
        $this->applyInstanceQueryUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $completedAt);
        $this->applyVoiceUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $completedAt);
        $this->applyWebspaceUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $completedAt);
        $this->applyGdprAnonymizationFromJob($job, $resultStatus, $agent->getId());
        $this->applyBackupUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $completedAt);
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

    #[Route(path: '/agent/jobs/{id}/logs', name: 'agent_job_logs', methods: ['POST'])]
    #[Route(path: '/api/v1/agent/jobs/{id}/logs', name: 'agent_job_logs_v1', methods: ['POST'])]
    public function jobLogs(Request $request, string $id): JsonResponse
    {
        $agent = $this->requireAgent($request);
        if ($agent instanceof JsonResponse) {
            return $agent;
        }
        $job = $this->jobRepository->find($id);

        if ($job === null) {
            throw new NotFoundHttpException('Job not found.');
        }

        if ($this->isDecommissionedAgent($agent)) {
            $this->markJobFailedForDecommissionedAgent($job, $agent, 'logs');

            return new JsonResponse(['error' => 'agent_decommissioned'], JsonResponse::HTTP_FORBIDDEN);
        }

        if (!in_array($job->getStatus(), [JobStatus::Claimed, JobStatus::Running], true)) {
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

        if ($job->getLockExpiresAt() === null || $job->getLockExpiresAt() <= new DateTimeImmutable()) {
            throw new ConflictHttpException('Job lock expired.');
        }

        if ($job->getStatus() === JobStatus::Claimed) {
            $job->transitionTo(JobStatus::Running);
            $this->jobLogger->log($job, 'Job started.', 10);
        }

        $job->extendLock(new DateTimeImmutable('+10 minutes'));

        $progress = $this->normalizeProgress($payload['progress'] ?? null);
        $lines = $this->normalizeLogLines($payload);

        if ($lines === []) {
            return new JsonResponse(['status' => 'ok']);
        }

        foreach ($lines as $line) {
            $this->jobLogger->log($job, $line, $progress);
        }

        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok']);
    }

    private function appendJobLogsFromOutput(\App\Module\Core\Domain\Entity\Job $job, array $output): void
    {
        $candidates = [];
        $keys = ['logs_tail', 'install_log', 'log_text', 'output', 'message'];
        foreach ($keys as $key) {
            if (isset($output[$key]) && is_string($output[$key]) && trim($output[$key]) !== '') {
                $candidates[] = (string) $output[$key];
            }
        }
        foreach (['stdout' => 'STDOUT', 'stderr' => 'STDERR'] as $key => $label) {
            if (isset($output[$key]) && is_string($output[$key]) && trim($output[$key]) !== '') {
                $candidates[] = sprintf('--- %s ---%s%s', $label, PHP_EOL, $output[$key]);
            }
        }
        if (isset($output['logs']) && is_array($output['logs'])) {
            foreach ($output['logs'] as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    $candidates[] = $entry;
                }
            }
        }

        if ($candidates === []) {
            return;
        }

        $lines = [];
        foreach ($candidates as $text) {
            $split = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
            foreach ($split as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                $lines[] = $line;
            }
        }

        $lines = array_slice($lines, 0, 200);
        foreach ($lines as $line) {
            $this->jobLogger->log($job, $line, null);
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeLogLines(array $payload): array
    {
        $candidates = [];
        $message = $payload['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            $candidates[] = $message;
        }

        foreach (['stdout' => 'STDOUT', 'stderr' => 'STDERR'] as $key => $label) {
            if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '') {
                $candidates[] = sprintf('--- %s ---%s%s', $label, PHP_EOL, $payload[$key]);
            }
        }

        if (isset($payload['logs']) && is_array($payload['logs'])) {
            foreach ($payload['logs'] as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    $candidates[] = $entry;
                }
            }
        }

        if ($candidates === []) {
            return [];
        }

        $lines = [];
        foreach ($candidates as $text) {
            $split = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
            foreach ($split as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                $lines[] = $line;
            }
        }

        return array_slice($lines, 0, 200);
    }

    private function normalizeProgress(mixed $progress): ?int
    {
        if (!is_numeric($progress)) {
            return null;
        }

        $value = (int) $progress;

        return max(0, min(100, $value));
    }

    private function requireAgent(Request $request): \App\Module\Core\Domain\Entity\Agent|JsonResponse
    {
        $rawAgentHeader = $request->headers->get('X-Agent-ID');
        $agentId = AgentSignatureVerifier::normalizeAgentIdHeaderValue($rawAgentHeader);
        if ($agentId === '') {
            return $this->unauthorizedAgentResponse('missing_agent_id');
        }

        $agent = $this->agentRepository->find($agentId);
        if ($agent === null) {
            $this->logger->warning('Agent not found for request.', [
                'agent_id' => $agentId,
                'agent_id_header' => $rawAgentHeader,
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'client_ip' => $request->getClientIp(),
            ]);

            return $this->unauthorizedAgentResponse('unknown_agent');
        }

        try {
            $secret = $this->encryptionService->decrypt($agent->getSecretPayload());
        } catch (\RuntimeException $exception) {
            $this->logger->error('Failed to decrypt agent secret for authenticated request.', [
                'agent_id' => $agentId,
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'client_ip' => $request->getClientIp(),
                'error' => $exception->getMessage(),
            ]);

            return $this->unauthorizedAgentResponse('invalid_agent_credentials');
        }

        try {
            $this->signatureVerifier->verify($request, $agentId, $secret);
        } catch (UnauthorizedHttpException $exception) {
            return $this->unauthorizedAgentResponse($exception->getMessage());
        }

        return $agent;
    }

    private function unauthorizedAgentResponse(string $reason): JsonResponse
    {
        return new JsonResponse([
            'error' => $reason,
        ], JsonResponse::HTTP_UNAUTHORIZED);
    }

    private function expireStaleJobs(DateTimeImmutable $now): void
    {
        $staleJobs = $this->jobRepository->findRunningWithExpiredLock($now);
        if ($staleJobs === []) {
            return;
        }

        foreach ($staleJobs as $job) {
            if (!in_array($job->getStatus(), [JobStatus::Running, JobStatus::Claimed], true)) {
                continue;
            }
            $job->clearLock();
            $job->clearClaim();
            if ($job->getAttempts() < $job->getMaxAttempts()) {
                $job->transitionTo(JobStatus::Queued);
                $this->jobLogger->log($job, 'Job lock expired; re-queued for retry.', 0);
                continue;
            }
            $job->transitionTo(JobStatus::Failed);
            $job->recordFailure('job_timeout', 'Job lock expired.');
            $this->jobLogger->log($job, 'Job lock expired; retries exhausted.', 100);
        }

        $this->entityManager->flush();
    }

    private function applyJobFailureContext(\App\Module\Core\Domain\Entity\Job $job, JobResultStatus $status, array $output): void
    {
        if ($status !== JobResultStatus::Failed) {
            return;
        }

        $errorCode = is_string($output['error_code'] ?? null) ? (string) $output['error_code'] : null;
        $message = null;
        foreach (['message', 'stderr', 'error'] as $key) {
            $value = $output[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $message = trim($value);
                break;
            }
        }

        if ($message !== null) {
            $message = $this->truncateError($message);
        }

        $job->recordFailure($errorCode, $message);
    }

    private function truncateError(string $message): string
    {
        $message = trim($message);
        if (strlen($message) <= 8192) {
            return $message;
        }

        return substr($message, 0, 8192);
    }

    private function isWindowsStats(array $stats): bool
    {
        $os = $stats['os'] ?? null;
        if (!is_string($os)) {
            return false;
        }
        return strtolower($os) === 'windows';
    }

    private function isWindowsAgent(\App\Module\Core\Domain\Entity\Agent $agent): bool
    {
        $stats = $agent->getLastHeartbeatStats();
        if (!is_array($stats)) {
            return false;
        }
        return $this->isWindowsStats($stats);
    }

    private function canDispatchJobToAgent(string $jobType, \App\Module\Core\Domain\Entity\Agent $agent): bool
    {
        $jobType = trim($jobType);
        if ($jobType === '') {
            return true;
        }

        if ($this->isWindowsAgent($agent)) {
            return in_array($jobType, self::WINDOWS_ALLOWED_JOB_TYPES, true);
        }

        if (str_starts_with($jobType, 'windows.service.')) {
            return false;
        }

        $metadata = $agent->getMetadata();
        if (!is_array($metadata)) {
            return true;
        }

        $capabilities = $metadata['capabilities'] ?? null;
        if (!is_array($capabilities) || $capabilities === []) {
            return true;
        }

        $normalizedCapabilities = array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $capabilities)));

        if ($normalizedCapabilities === []) {
            return true;
        }

        return in_array($jobType, $normalizedCapabilities, true);
    }

    private function isDecommissionedAgent(Agent $agent): bool
    {
        return $agent->getStatus() === Agent::STATUS_DECOMMISSIONED;
    }

    private function cancelPendingJobsForDecommissionedAgent(Agent $agent, string $context): int
    {
        $jobs = $this->jobRepository->createQueryBuilder('job')
            ->where('job.payload LIKE :agentPayload')
            ->andWhere('job.status IN (:statuses)')
            ->setParameter('agentPayload', '%"agent_id":"' . $agent->getId() . '"%')
            ->setParameter('statuses', [JobStatus::Queued, JobStatus::Claimed, JobStatus::Running])
            ->getQuery()
            ->getResult();

        $cancelled = 0;
        foreach ($jobs as $job) {
            if (!$job instanceof Job) {
                continue;
            }

            if (!in_array($job->getStatus(), [JobStatus::Queued, JobStatus::Claimed, JobStatus::Running], true)) {
                continue;
            }

            $job->clearLock();
            $job->clearClaim();
            $job->transitionTo(JobStatus::Cancelled);
            $job->recordFailure('agent_decommissioned', sprintf('Agent decommissioned; dispatch blocked during %s.', $context));
            $this->jobLogger->log($job, 'Cancelled because node is decommissioned.', 100);
            $cancelled++;

            $this->auditLogger->log(null, 'agent.job_cancelled_decommissioned', [
                'agent_id' => $agent->getId(),
                'job_id' => $job->getId(),
                'context' => $context,
            ]);
        }

        if ($cancelled > 0) {
            $this->entityManager->flush();
        }

        return $cancelled;
    }

    private function markJobFailedForDecommissionedAgent(Job $job, Agent $agent, string $context): void
    {
        if (!in_array($job->getStatus(), [JobStatus::Claimed, JobStatus::Running], true)) {
            return;
        }

        $job->clearLock();
        $job->clearClaim();
        $job->transitionTo(JobStatus::Failed);
        $job->recordFailure('agent_decommissioned', sprintf('Agent decommissioned; execution blocked during %s.', $context));
        $this->jobLogger->log($job, 'Failed because node is decommissioned.', 100);
        $this->auditLogger->log(null, 'agent.job_failed_decommissioned', [
            'agent_id' => $agent->getId(),
            'job_id' => $job->getId(),
            'context' => $context,
        ]);
        $this->entityManager->flush();
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

    private function buildMetricSample(\App\Module\Core\Domain\Entity\Agent $agent, mixed $metrics): ?MetricSample
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

    private function ingestInstanceMetrics(mixed $payload): void
    {
        if (!is_array($payload)) {
            return;
        }

        $samples = is_array($payload['samples'] ?? null) ? $payload['samples'] : [];
        $retentionThreshold = (new \DateTimeImmutable('-1 day'));

        foreach ($samples as $sample) {
            if (!is_array($sample)) {
                continue;
            }

            $instanceId = $sample['instance_id'] ?? null;
            if (!is_numeric($instanceId)) {
                continue;
            }

            $instance = $this->instanceRepository->find((int) $instanceId);
            if ($instance === null) {
                continue;
            }

            $metricSample = new InstanceMetricSample(
                $instance,
                is_numeric($sample['cpu_percent'] ?? null) ? (float) $sample['cpu_percent'] : null,
                is_numeric($sample['mem_current_bytes'] ?? null) ? (int) $sample['mem_current_bytes'] : null,
                is_numeric($sample['tasks_current'] ?? null) ? (int) $sample['tasks_current'] : null,
                $this->parseMetricTimestamp($sample['collected_at'] ?? null),
                is_string($sample['error_code'] ?? null) ? (string) $sample['error_code'] : null,
            );
            $this->entityManager->persist($metricSample);
        }

        $this->instanceMetricSampleRepository->deleteOlderThan($retentionThreshold);
    }



    private function applyWebspaceUpdatesFromJob(\App\Module\Core\Domain\Entity\Job $job, JobResultStatus $resultStatus, string $agentId, array $output, DateTimeImmutable $completedAt): void
    {
        if (!in_array($job->getType(), ['webspace.provision', 'webspace.apply', 'webspace.domain.apply'], true)) {
            return;
        }

        $payload = $job->getPayload();
        $webspaceId = (int) ($payload['webspace_id'] ?? 0);
        if ($webspaceId <= 0) {
            return;
        }

        $webspace = $this->webspaceRepository->find($webspaceId);
        if ($webspace === null || $webspace->getNode()->getId() !== $agentId) {
            return;
        }

        if ($resultStatus === JobResultStatus::Succeeded) {
            $agentApplyStatus = is_string($output['apply_status'] ?? null) ? trim((string) $output['apply_status']) : 'succeeded';
            $normalizedApplyStatus = in_array($agentApplyStatus, ['succeeded', 'success'], true) ? 'succeeded' : $agentApplyStatus;

            if ($job->getType() === 'webspace.provision') {
                $webspace->setApplyStatus($normalizedApplyStatus);
                $webspace->setApplyRequired(true);
                $webspace->setApplyError(null, null);
            } else {
                $webspace->markApplied(hash('sha256', implode('|', [
                    $webspace->getRuntime(),
                    $webspace->getPath(),
                    $webspace->getDocroot(),
                    $webspace->getDomain(),
                    $completedAt->format(DATE_RFC3339),
                ])));
                $webspace->setApplyStatus($normalizedApplyStatus);
            }

            if ($job->getType() === 'webspace.domain.apply') {
                $domainId = (int) ($payload['domain_id'] ?? 0);
                if ($domainId > 0) {
                    $domain = $this->domainRepository->find($domainId);
                    if ($domain !== null) {
                        $domain->markApplied();
                    }
                }
            }

            return;
        }

        $errorCode = is_string($output['error_code'] ?? null) ? (string) $output['error_code'] : 'webspace_apply_failed';
        $errorMessage = is_string($output['error_message'] ?? ($output['message'] ?? null)) ? trim((string) ($output['error_message'] ?? $output['message'])) : 'Apply failed.';
        $webspace->setApplyStatus('failed');
        $webspace->setApplyRequired(true);
        $webspace->setApplyError(substr($errorCode, 0, 64), substr($errorMessage, 0, 500));

        if ($job->getType() === 'webspace.domain.apply') {
            $domainId = (int) ($payload['domain_id'] ?? 0);
            if ($domainId > 0) {
                $domain = $this->domainRepository->find($domainId);
                if ($domain !== null) {
                    $domain->setApplyStatus('failed');
                    $domain->setApplyError(substr($errorCode, 0, 64), substr($errorMessage, 0, 500));
                }
            }
        }
    }
    private function applyDatabaseUpdatesFromJob(\App\Module\Core\Domain\Entity\Job $job, JobResultStatus $resultStatus, string $agentId, array $output, ?string $databaseCredential): void
    {
        if (!in_array($job->getType(), ['database.create', 'database.rotate_password', 'database.delete'], true)) {
            return;
        }

        $payload = $job->getPayload();
        $databaseId = (int) ($payload['database_id'] ?? 0);
        if ($databaseId <= 0) {
            return;
        }

        $database = $this->databaseRepository->find($databaseId);
        if ($database === null) {
            return;
        }

        if ($database->getNode()?->getAgent()->getId() !== $agentId) {
            return;
        }

        if ($resultStatus === JobResultStatus::Succeeded) {
            if ($job->getType() === 'database.delete') {
                $this->entityManager->remove($database);
                return;
            }

            $database->setStatus('provisioned');
            $database->setLastError(null, null);
            if (is_string($databaseCredential) && $databaseCredential !== '') {
                $database->setEncryptedPassword($this->encryptionService->encrypt($databaseCredential));
            }
            if ($job->getType() === 'database.rotate_password') {
                $database->markRotated();
            }

            return;
        }

        if ($job->getType() === 'database.delete') {
            $database->setStatus('failed');
        } else {
            $database->setStatus('failed');
        }

        $errorCode = is_string($output['error_code'] ?? null) ? (string) $output['error_code'] : 'db_action_failed';
        $message = is_string($output['error_message'] ?? null) ? trim((string) $output['error_message']) : 'Database operation failed.';
        $database->setLastError(substr($errorCode, 0, 120), substr($message, 0, 500));
    }
    private function applyDdosStatusFromJob(
        \App\Module\Core\Domain\Entity\Job $job,
        JobResultStatus $resultStatus,
        \App\Module\Core\Domain\Entity\Agent $agent,
        array $output,
        DateTimeImmutable $completedAt,
    ): void {
        if ($job->getType() !== 'ddos.status.check') {
            return;
        }

        if ($resultStatus !== JobResultStatus::Succeeded) {
            return;
        }

        $attackActive = $this->parseDdosBool($output['attack_active'] ?? null) ?? false;
        $packetsPerSecond = $this->parseDdosInt($output['pps'] ?? null);
        $connectionCount = $this->parseDdosInt($output['conn_count'] ?? null);
        $ports = $this->parseDdosPorts($output['ports'] ?? null);
        $protocols = $this->parseDdosProtocols($output['protocols'] ?? null);

        $mode = is_string($output['mode'] ?? null) ? trim((string) $output['mode']) : null;
        if ($mode === '') {
            $mode = null;
        }

        $reportedAt = $this->parseDdosTimestamp($output['reported_at'] ?? $output['checked_at'] ?? null, $completedAt);

        $status = $this->ddosStatusRepository->findOneBy(['node' => $agent]);
        if ($status === null) {
            $status = new DdosStatus(
                $agent,
                $attackActive,
                $packetsPerSecond,
                $connectionCount,
                $ports,
                $protocols,
                $mode,
                $reportedAt,
            );
        } else {
            $status->updateStatus(
                $attackActive,
                $packetsPerSecond,
                $connectionCount,
                $ports,
                $protocols,
                $mode,
                $reportedAt,
            );
        }

        $this->entityManager->persist($status);
        $this->auditLogger->log(null, 'ddos.status.reported', [
            'agent_id' => $agent->getId(),
            'attack_active' => $attackActive,
            'pps' => $packetsPerSecond,
            'conn_count' => $connectionCount,
            'ports' => $ports,
            'protocols' => $protocols,
            'mode' => $mode,
            'reported_at' => $reportedAt->format(DATE_RFC3339),
        ]);

        if ($attackActive) {
            $event = new SecurityEvent(
                $agent,
                'blocked',
                'ddos',
                'attack_active',
                null,
                null,
                $connectionCount,
                $reportedAt,
            );
            $this->entityManager->persist($event);
        }
    }

    private function applyDdosPolicyFromJob(
        \App\Module\Core\Domain\Entity\Job $job,
        JobResultStatus $resultStatus,
        \App\Module\Core\Domain\Entity\Agent $agent,
        array $output,
        DateTimeImmutable $completedAt,
    ): void {
        if ($job->getType() !== 'ddos.policy.apply') {
            return;
        }

        if ($resultStatus !== JobResultStatus::Succeeded) {
            return;
        }

        $payload = $job->getPayload();
        $ports = $this->parseDdosPorts($output['ports'] ?? $payload['ports'] ?? null);
        $protocols = $this->parseDdosProtocols($output['protocols'] ?? $payload['protocols'] ?? null);

        $mode = is_string($output['mode'] ?? null) ? trim((string) $output['mode']) : null;
        if ($mode === null || $mode === '') {
            $mode = is_string($payload['mode'] ?? null) ? trim((string) $payload['mode']) : null;
        }
        if ($mode === '') {
            $mode = null;
        }

        $enabled = $this->parseDdosBool($output['enabled'] ?? $output['active'] ?? null);
        if ($enabled === null) {
            $enabled = $mode !== 'off';
        }

        $appliedAt = $this->parseDdosTimestamp($output['applied_at'] ?? null, $completedAt);

        $policy = $this->ddosPolicyRepository->findOneBy(['node' => $agent]);
        if ($policy === null) {
            $policy = new DdosPolicy($agent, $ports, $protocols, $mode, $enabled, $appliedAt);
        } else {
            $policy->updatePolicy($ports, $protocols, $mode, $enabled, $appliedAt);
        }

        $this->entityManager->persist($policy);
        $this->auditLogger->log(null, 'ddos.policy.applied', [
            'agent_id' => $agent->getId(),
            'mode' => $mode,
            'enabled' => $enabled,
            'ports' => $ports,
            'protocols' => $protocols,
            'applied_at' => $appliedAt->format(DATE_RFC3339),
        ]);
    }

    private function applyFail2banStatusFromJob(
        \App\Module\Core\Domain\Entity\Job $job,
        JobResultStatus $resultStatus,
        \App\Module\Core\Domain\Entity\Agent $agent,
        array $output,
        DateTimeImmutable $completedAt,
    ): void {
        if ($job->getType() !== 'fail2ban.status.check') {
            return;
        }

        if ($resultStatus !== JobResultStatus::Succeeded) {
            return;
        }

        $jails = $output['jails'] ?? null;
        if (is_string($jails) && $jails !== '') {
            $decoded = json_decode($jails, true);
            $jails = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($jails)) {
            return;
        }

        foreach ($jails as $jail) {
            if (!is_array($jail)) {
                continue;
            }

            $name = is_string($jail['name'] ?? null) ? (string) $jail['name'] : null;
            $banned = $jail['banned'] ?? null;
            $banned = is_numeric($banned) ? (int) $banned : null;
            $bannedIps = $jail['banned_ips'] ?? [];
            if (!is_array($bannedIps)) {
                $bannedIps = [];
            }

            foreach ($bannedIps as $ip) {
                if (!is_string($ip) || trim($ip) === '') {
                    continue;
                }

                $event = new SecurityEvent(
                    $agent,
                    'blocked',
                    'fail2ban',
                    $name === null ? null : sprintf('jail:%s', $name),
                    $ip,
                    $name,
                    1,
                    $completedAt,
                );
                $this->entityManager->persist($event);
            }

            if ($banned !== null) {
                $event = new SecurityEvent(
                    $agent,
                    'blocked',
                    'fail2ban',
                    $name === null ? null : sprintf('jail:%s', $name),
                    null,
                    $name,
                    $banned,
                    $completedAt,
                );
                $this->entityManager->persist($event);
            }
        }

        $this->auditLogger->log(null, 'fail2ban.status.reported', [
            'agent_id' => $agent->getId(),
            'reported_at' => $completedAt->format(DATE_RFC3339),
        ]);
    }

    private function applyFail2banPolicyFromJob(
        \App\Module\Core\Domain\Entity\Job $job,
        JobResultStatus $resultStatus,
        \App\Module\Core\Domain\Entity\Agent $agent,
        array $output,
        DateTimeImmutable $completedAt,
    ): void {
        if ($job->getType() !== 'fail2ban.policy.apply') {
            return;
        }

        if ($resultStatus !== JobResultStatus::Succeeded) {
            return;
        }

        $payload = $job->getPayload();
        $policy = is_array($payload['policy'] ?? null) ? $payload['policy'] : [];
        $this->auditLogger->log(null, 'fail2ban.policy.applied', [
            'agent_id' => $agent->getId(),
            'policy' => $policy,
            'applied_at' => $completedAt->format(DATE_RFC3339),
        ]);
    }

    private function applySecurityEventsFromJob(
        \App\Module\Core\Domain\Entity\Job $job,
        JobResultStatus $resultStatus,
        \App\Module\Core\Domain\Entity\Agent $agent,
        array $output,
        DateTimeImmutable $completedAt,
    ): void {
        if ($job->getType() !== 'security.events.collect' || $resultStatus !== JobResultStatus::Succeeded) {
            return;
        }

        $schema = is_string($output['schema'] ?? null) ? trim((string) $output['schema']) : 'security.events.v1';
        if ($schema !== 'security.events.v1') {
            return;
        }

        $rawEvents = $output['events'] ?? null;
        if (!is_string($rawEvents) || $rawEvents === '') {
            return;
        }
        if (strlen($rawEvents) > 262144) {
            return;
        }

        $events = json_decode($rawEvents, true);
        if (!is_array($events)) {
            return;
        }

        $ttlRaw = is_string($output['retention_ttl'] ?? null) ? trim($output['retention_ttl']) : '24h';
        $expiresAt = $this->resolveSecurityEventExpiry($completedAt, $ttlRaw);
        $this->cleanupExpiredSecurityEvents();

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $direction = is_string($event['direction'] ?? null) ? $event['direction'] : 'blocked';
            $direction = in_array($direction, ['blocked', 'allowed'], true) ? $direction : 'blocked';
            $source = is_string($event['source'] ?? null) ? mb_substr(trim((string) $event['source']), 0, 32) : 'firewall';
            $reason = is_string($event['reason'] ?? null) ? mb_substr(trim((string) $event['reason']), 0, 120) : null;
            $ip = is_string($event['ip'] ?? null) ? mb_substr(trim((string) $event['ip']), 0, 64) : null;
            $rule = is_string($event['rule'] ?? null) ? mb_substr(trim((string) $event['rule']), 0, 120) : null;
            $count = is_int($event['count'] ?? null) && $event['count'] > 0 ? $event['count'] : 1;
            $dedupKey = hash('sha256', implode('|', [$agent->getId(), $direction, $source, $ip ?? '', $rule ?? '', $completedAt->format(DATE_RFC3339)]));
            if ($this->entityManager->getRepository(SecurityEvent::class)->findOneBy(['dedupKey' => $dedupKey]) !== null) {
                continue;
            }

            $this->entityManager->persist(new SecurityEvent(
                $agent,
                $direction,
                $source,
                $reason,
                $ip,
                $rule,
                $count,
                $completedAt,
                $dedupKey,
                $expiresAt,
            ));
        }
    }

    private function resolveSecurityEventExpiry(DateTimeImmutable $base, string $ttlRaw): DateTimeImmutable
    {
        $ttlRaw = strtolower($ttlRaw);
        if (!preg_match('/^(\d+)([smhd])$/', $ttlRaw, $match)) {
            return $base->modify('+24 hours');
        }

        $value = max(1, (int) $match[1]);
        $unit = $match[2];
        $maxSeconds = 30 * 24 * 60 * 60;
        $seconds = match ($unit) {
            's' => $value,
            'm' => $value * 60,
            'h' => $value * 3600,
            default => $value * 86400,
        };

        return $base->modify(sprintf('+%d seconds', min($seconds, $maxSeconds)));
    }

    private function cleanupExpiredSecurityEvents(): void
    {
        $this->entityManager->createQuery('DELETE FROM App\Module\Core\Domain\Entity\SecurityEvent e WHERE e.expiresAt < :now')
            ->setParameter('now', new DateTimeImmutable())
            ->execute();
    }

    private function applyPolicyRevisionStatusFromJob(
        \App\Module\Core\Domain\Entity\Job $job,
        JobResultStatus $resultStatus,
        DateTimeImmutable $completedAt,
    ): void {
        $payload = $job->getPayload();
        $revisionId = $payload['policy_revision_id'] ?? null;
        if (!is_int($revisionId) && !is_string($revisionId)) {
            return;
        }

        $revision = $this->policyRevisionRepository->find((int) $revisionId);
        if ($revision === null) {
            return;
        }

        if ($resultStatus === JobResultStatus::Succeeded) {
            $revision->markApplied($completedAt);
        } elseif ($resultStatus === JobResultStatus::Failed) {
            $revision->markFailed();
        }
    }

    private function parseDdosTimestamp(mixed $value, DateTimeImmutable $fallback): DateTimeImmutable
    {
        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (\Exception) {
            }
        }

        return $fallback;
    }

    private function parseDdosBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value) || is_numeric($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }

    private function parseDdosInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return int[]
     */
    private function parseDdosPorts(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $entries = array_map('trim', explode(',', $value));
                $parsed = [];
                foreach ($entries as $entry) {
                    if ($entry === '' || !ctype_digit($entry)) {
                        continue;
                    }
                    $parsed[] = (int) $entry;
                }
                $value = $parsed;
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $ports = [];
        foreach ($value as $port) {
            if (!is_numeric($port)) {
                continue;
            }

            $portValue = (int) $port;
            if ($portValue <= 0 || $portValue > 65535) {
                continue;
            }

            $ports[] = $portValue;
        }

        $ports = array_values(array_unique($ports));
        sort($ports);
        return $ports;
    }

    /**
     * @return string[]
     */
    private function parseDdosProtocols(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = array_map('trim', explode(',', $value));
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $protocols = [];
        foreach ($value as $protocol) {
            if (!is_string($protocol)) {
                continue;
            }

            $normalized = strtolower(trim($protocol));
            if (!in_array($normalized, ['tcp', 'udp'], true)) {
                continue;
            }

            $protocols[] = $normalized;
        }

        $protocols = array_values(array_unique($protocols));
        sort($protocols);
        return $protocols;
    }

    private function applyDomainUpdatesFromJob(\App\Module\Core\Domain\Entity\Job $job, JobResultStatus $resultStatus, string $agentId, array $output): void
    {
        $payload = $job->getPayload();
        $domain = null;
        $domainId = $payload['domain_id'] ?? null;
        if (is_int($domainId) || is_string($domainId)) {
            $domain = $this->domainRepository->find((int) $domainId);
        }
        if ($domain === null && $job->getType() === 'domain.ssl.issue') {
            $domainName = is_string($payload['domain'] ?? null) ? trim((string) $payload['domain']) : '';
            if ($domainName !== '') {
                $domain = $this->domainRepository->findOneBy(['name' => $domainName]);
            }
        }
        if ($domain === null) {
            return;
        }

        if ($job->getType() !== 'domain.ssl.issue') {
            return;
        }

        if ($resultStatus === JobResultStatus::Failed || $resultStatus === JobResultStatus::Cancelled) {
            $certificate = $this->entityManager->getRepository(Certificate::class)->findOneBy(['domain' => $domain], ['id' => 'DESC']);
            if (!$certificate instanceof Certificate) {
                $certificate = new Certificate($domain);
            }
            $certificate->setStatus('failed');
            $errorMessage = is_string($output['error_message'] ?? ($output['message'] ?? null)) ? trim((string) ($output['error_message'] ?? $output['message'])) : 'TLS issuance failed.';
            $certificate->setLastError(substr($errorMessage, 0, 1000));
            $this->entityManager->persist($certificate);
            return;
        }

        $expiresAt = $this->parseSslExpiry($output['expires_at'] ?? null);
        if ($expiresAt === null) {
            return;
        }

        $certificate = $this->entityManager->getRepository(Certificate::class)->findOneBy(['domain' => $domain], ['id' => 'DESC']);
        if (!$certificate instanceof Certificate) {
            $certificate = new Certificate($domain);
        }
        $certificate->setStatus('success');
        $certificate->setExpiresAt($expiresAt);
        $certificate->setLastError(null);
        $this->entityManager->persist($certificate);

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

    private function applyTs3UpdatesFromJob(\App\Module\Core\Domain\Entity\Job $job, JobResultStatus $resultStatus, string $agentId, array $output): void
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
            $newStatus = \App\Module\Core\Domain\Enum\Ts3InstanceStatus::Error;
        } elseif ($resultStatus === JobResultStatus::Succeeded) {
            $newStatus = match ($job->getType()) {
                'ts3.create', 'ts3.start', 'ts3.restart', 'ts3.update', 'ts3.restore' => \App\Module\Core\Domain\Enum\Ts3InstanceStatus::Running,
                'ts3.stop' => \App\Module\Core\Domain\Enum\Ts3InstanceStatus::Stopped,
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

    private function applyTs6UpdatesFromJob(\App\Module\Core\Domain\Entity\Job $job, JobResultStatus $resultStatus, string $agentId, array $output): void
    {
        $payload = $job->getPayload();
        $instanceId = $payload['ts6_instance_id'] ?? $payload['instance_id'] ?? null;
        if (!is_int($instanceId) && !is_string($instanceId)) {
            return;
        }

        $instance = $this->ts6InstanceRepository->find((int) $instanceId);
        if ($instance === null) {
            return;
        }

        $newStatus = null;
        if ($resultStatus === JobResultStatus::Failed) {
            $newStatus = \App\Module\Core\Domain\Enum\Ts6InstanceStatus::Error;
        } elseif ($resultStatus === JobResultStatus::Succeeded) {
            $newStatus = match ($job->getType()) {
                'ts6.instance.create',
                'ts6.instance.start',
                'ts6.instance.restart',
                'ts6.instance.update',
                'ts6.instance.restore' => \App\Module\Core\Domain\Enum\Ts6InstanceStatus::Running,
                'ts6.instance.stop' => \App\Module\Core\Domain\Enum\Ts6InstanceStatus::Stopped,
                default => null,
            };
        }

        if ($newStatus === null || $instance->getStatus() === $newStatus) {
            return;
        }

        $previousStatus = $instance->getStatus()->value;
        $instance->setStatus($newStatus);
        $this->entityManager->persist($instance);
        $this->auditLogger->log(null, 'ts6.instance_status_updated', [
            'instance_id' => $instance->getId(),
            'job_id' => $job->getId(),
            'agent_id' => $agentId,
            'previous_status' => $previousStatus,
            'status' => $newStatus->value,
            'output' => $output,
        ]);
    }

    private function applyInstanceUpdatesFromJob(
        \App\Module\Core\Domain\Entity\Job $job,
        JobResultStatus $resultStatus,
        string $agentId,
        array $output,
        DateTimeImmutable $completedAt,
    ): void {
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

        if ($instance->getStatus() === InstanceStatus::Suspended) {
            return;
        }

        $newStatus = null;
        if ($resultStatus === JobResultStatus::Failed) {
            if (in_array($job->getType(), ['instance.create', 'instance.start', 'instance.restart', 'instance.stop', 'instance.reinstall', 'sniper.install'], true)) {
                $newStatus = \App\Module\Core\Domain\Enum\InstanceStatus::Error;
            }
        } elseif ($resultStatus === JobResultStatus::Succeeded) {
            $newStatus = match ($job->getType()) {
                'instance.create', 'instance.start', 'instance.restart' => \App\Module\Core\Domain\Enum\InstanceStatus::Running,
                'instance.stop', 'instance.reinstall', 'sniper.install', 'sniper.update' => \App\Module\Core\Domain\Enum\InstanceStatus::Stopped,
                default => null,
            };
        }

        if ($newStatus !== null && $instance->getStatus() !== $newStatus) {
            $previousStatus = $instance->getStatus()->value;
            $instance->setStatus($newStatus);
            $this->entityManager->persist($instance);
            $this->auditLogger->log(null, 'instance.status_updated', [
                'instance_id' => $instance->getId(),
                'job_id' => $job->getId(),
                'agent_id' => $agentId,
                'previous_status' => $previousStatus,
                'status' => $newStatus->value,
                'output' => $output,
            ]);
        }

        if ($resultStatus !== JobResultStatus::Succeeded) {
            return;
        }

        if (in_array($job->getType(), ['instance.create', 'instance.start', 'instance.restart', 'instance.reinstall'], true)) {
            $this->queueDiskScanIfNeeded($instance, $completedAt);
            $this->queueAutoAccessProvisionIfNeeded($instance, $job);
        }

        if (is_string($output['start_script_path'] ?? null)) {
            $instance->setStartScriptPath($output['start_script_path']);
            $this->entityManager->persist($instance);
        }

        if (!in_array($job->getType(), ['sniper.install', 'sniper.update'], true)) {
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

    private function applyInstanceSftpCredentialUpdatesFromJob(
        \App\Module\Core\Domain\Entity\Job $job,
        JobResultStatus $resultStatus,
        string $agentId,
        array &$output,
        DateTimeImmutable $completedAt,
    ): void {
        if ($job->getType() !== 'instance.sftp.credentials.reset' || $resultStatus !== JobResultStatus::Succeeded) {
            return;
        }

        $payload = $job->getPayload();
        $instanceId = $payload['instance_id'] ?? null;
        if (!is_int($instanceId) && !is_string($instanceId)) {
            return;
        }

        $instance = $this->instanceRepository->find((int) $instanceId);
        if ($instance === null || $instance->getNode()->getId() !== $agentId) {
            return;
        }

        $password = is_string($payload['password'] ?? null) ? trim((string) $payload['password']) : '';
        if ($password === '') {
            $password = bin2hex(random_bytes(18));
        }

        $credential = $this->instanceSftpCredentialRepository->findOneByInstance($instance);
        if ($credential === null) {
            $credential = new \App\Module\Core\Domain\Entity\InstanceSftpCredential(
                $instance,
                (string) ($output['username'] ?? sprintf('sftp%d', $instance->getId())),
                $this->encryptionService->encrypt($password),
            );
        } else {
            $credential->setEncryptedPassword($this->encryptionService->encrypt($password));
        }

        $backend = is_string($output['backend'] ?? null) ? (string) $output['backend'] : 'NONE';
        $host = is_string($output['host'] ?? null) ? (string) $output['host'] : null;
        $port = is_numeric($output['port'] ?? null) ? (int) $output['port'] : null;
        $rootPath = is_string($output['root_path'] ?? null) ? (string) $output['root_path'] : null;
        $credential->setBackend($backend);
        $credential->setHost($host);
        $credential->setPort($port);
        $credential->setRootPath($rootPath);
        $credential->setLastError(null, null);

        $expiresAt = null;
        $expiresRaw = is_string($payload['expires_at'] ?? null) ? (string) $payload['expires_at'] : null;
        if ($expiresRaw !== null && $expiresRaw !== '') {
            try {
                $expiresAt = new DateTimeImmutable($expiresRaw);
            } catch (\Exception) {
                $expiresAt = null;
            }
        }

        $credential->setRotatedAt($completedAt);
        $credential->setExpiresAt($expiresAt);
        $credential->setRevealedAt(null);
        $this->entityManager->persist($credential);

        $this->auditLogger->log(null, 'instance.sftp.credentials.rotated', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'job_id' => $job->getId(),
            'credential_id' => $credential->getId(),
            'rotated_at' => $completedAt->format(DATE_RFC3339),
            'expires_at' => $expiresAt?->format(DATE_RFC3339),
        ]);

    }

    private function queueDiskScanIfNeeded(\App\Module\Core\Domain\Entity\Instance $instance, DateTimeImmutable $completedAt): void
    {
        if ($instance->getDiskLastScannedAt() !== null) {
            return;
        }

        $recentJobs = $this->jobRepository->findLatestByType('instance.disk.scan', 50);
        foreach ($recentJobs as $job) {
            $payload = $job->getPayload();
            if ((string) ($payload['instance_id'] ?? '') !== (string) $instance->getId()) {
                continue;
            }
            if (in_array($job->getStatus(), [JobStatus::Queued, JobStatus::Claimed, JobStatus::Running], true)) {
                return;
            }
        }

        $payload = [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'instance_dir' => $this->resolveInstanceDir($instance),
        ];

        $job = new \App\Module\Core\Domain\Entity\Job('instance.disk.scan', $payload);
        $this->entityManager->persist($job);
        $this->jobLogger->log($job, 'Job queued.', 0);

        $this->auditLogger->log(null, 'instance.disk.scan_queued', [
            'instance_id' => $instance->getId(),
            'node_id' => $instance->getNode()->getId(),
            'job_id' => $job->getId(),
            'queued_at' => $completedAt->format(DATE_RFC3339),
        ]);
    }

    private function queueAutoAccessProvisionIfNeeded(Instance $instance, Job $sourceJob): void
    {
        $existingCredential = $this->instanceSftpCredentialRepository->findOneByInstance($instance);
        if ($existingCredential !== null) {
            return;
        }

        $activeReset = $this->jobRepository->findLatestByTypeAndInstanceId('instance.sftp.credentials.reset', $instance->getId());
        if ($activeReset !== null && in_array($activeReset->getStatus(), [JobStatus::Queued, JobStatus::Claimed, JobStatus::Running], true)) {
            return;
        }

        $password = bin2hex(random_bytes(18));
        $expiresAt = (new DateTimeImmutable('+15 minutes'))->setTimezone(new \DateTimeZone('UTC'));
        $credential = new \App\Module\Core\Domain\Entity\InstanceSftpCredential(
            $instance,
            sprintf('gs_%d_%d', $instance->getId(), $instance->getCustomer()->getId()),
            $this->encryptionService->encrypt($password),
        );
        $credential->setExpiresAt($expiresAt);
        $credential->setRevealedAt(null);
        $credential->setBackend('NONE');
        $credential->setRootPath($this->resolveInstanceDir($instance));
        $this->entityManager->persist($credential);
        $this->entityManager->flush();

        $job = new Job('instance.sftp.credentials.reset', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'credential_id' => $credential->getId(),
            'username' => $credential->getUsername(),
            'password' => $password,
            'root_path' => $this->resolveInstanceDir($instance),
            'preferred_backend' => strtoupper(PHP_OS_FAMILY) === 'WINDOWS' ? 'WINDOWS_OPENSSH_SFTP' : 'PROFTPD_SFTP',
            'rotate' => true,
            'expires_at' => $expiresAt->format(DATE_RFC3339),
        ]);
        $this->entityManager->persist($job);

        $this->auditLogger->log(null, 'instance.access.auto_provision_queued', [
            'instance_id' => $instance->getId(),
            'source_job_id' => $sourceJob->getId(),
            'job_id' => $job->getId(),
            'username' => $credential->getUsername(),
            'backend' => $credential->getBackend(),
        ]);
    }

    private function applyDiskUpdatesFromJob(
        \App\Module\Core\Domain\Entity\Job $job,
        JobResultStatus $resultStatus,
        string $agentId,
        array $output,
        DateTimeImmutable $completedAt,
    ): void {
        if ($job->getType() === 'instance.disk.scan') {
            $payload = $job->getPayload();
            $instanceId = $payload['instance_id'] ?? null;
            if (!is_int($instanceId) && !is_string($instanceId)) {
                return;
            }

            $instance = $this->instanceRepository->find((int) $instanceId);
            if ($instance === null || $instance->getNode()->getId() !== $agentId) {
                return;
            }

            if ($resultStatus === JobResultStatus::Succeeded) {
                $usedBytes = is_numeric($output['used_bytes'] ?? null) ? (int) $output['used_bytes'] : null;
                if ($usedBytes === null) {
                    return;
                }

                $instance->setDiskUsedBytes($usedBytes);
                $instance->setDiskLastScannedAt($completedAt);
                $instance->setDiskScanError(null);
                $this->entityManager->persist($instance);

                $this->auditLogger->log(null, 'instance.disk.scanned', [
                    'instance_id' => $instance->getId(),
                    'node_id' => $instance->getNode()->getId(),
                    'job_id' => $job->getId(),
                    'disk_used_bytes' => $usedBytes,
                    'inode_count' => is_numeric($output['inode_count'] ?? null) ? (int) $output['inode_count'] : null,
                    'completed_at' => $completedAt->format(DATE_RFC3339),
                ]);
            } else {
                $message = is_string($output['message'] ?? null) ? $output['message'] : 'Disk scan failed.';
                $instance->setDiskLastScannedAt($completedAt);
                $instance->setDiskScanError($message);
                $this->entityManager->persist($instance);

                $this->auditLogger->log(null, 'instance.disk.scan_failed', [
                    'instance_id' => $instance->getId(),
                    'node_id' => $instance->getNode()->getId(),
                    'job_id' => $job->getId(),
                    'message' => $message,
                ]);
            }

            return;
        }

        if ($job->getType() !== 'node.disk.stat') {
            return;
        }

        $payload = $job->getPayload();
        $nodeId = $payload['node_id'] ?? $payload['agent_id'] ?? null;
        if (!is_string($nodeId) || $nodeId === '') {
            return;
        }

        $node = $this->agentRepository->find($nodeId);
        if ($node === null) {
            return;
        }

        if ($resultStatus !== JobResultStatus::Succeeded) {
            $this->auditLogger->log(null, 'node.disk.stat_failed', [
                'node_id' => $node->getId(),
                'job_id' => $job->getId(),
                'message' => $output['message'] ?? 'Disk stat failed.',
            ]);
            return;
        }

        $freeBytes = is_numeric($output['free_bytes'] ?? null) ? (int) $output['free_bytes'] : null;
        $freePercent = is_numeric($output['free_percent'] ?? null) ? (float) $output['free_percent'] : null;
        if ($freeBytes === null || $freePercent === null) {
            return;
        }

        $states = $this->nodeDiskProtectionService->updateDiskStat($node, $freeBytes, $freePercent, $completedAt);
        $this->entityManager->persist($node);

        $this->auditLogger->log(null, 'node.disk.stat_updated', [
            'node_id' => $node->getId(),
            'job_id' => $job->getId(),
            'free_bytes' => $freeBytes,
            'free_percent' => $freePercent,
            'checked_at' => $completedAt->format(DATE_RFC3339),
        ]);

        if ($node->getNodeDiskProtectionOverrideUntil() !== null && $freePercent >= $node->getNodeDiskProtectionThresholdPercent()) {
            $node->setNodeDiskProtectionOverrideUntil(null);
            $this->entityManager->persist($node);
            $this->auditLogger->log(null, 'node.disk.protection_override_cleared', [
                'node_id' => $node->getId(),
                'free_percent' => $freePercent,
            ]);
        }

        if ($states['previous'] !== $states['current']) {
            $this->auditLogger->log(null, 'node.disk.protection_state_changed', [
                'node_id' => $node->getId(),
                'previous' => $states['previous'],
                'current' => $states['current'],
                'free_percent' => $freePercent,
                'free_bytes' => $freeBytes,
            ]);
        }
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
        \App\Module\Core\Domain\Entity\Job $job,
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

    private function applyInstanceQueryUpdatesFromJob(
        \App\Module\Core\Domain\Entity\Job $job,
        JobResultStatus $resultStatus,
        string $agentId,
        array $output,
        DateTimeImmutable $completedAt,
    ): void {
        if ($job->getType() !== 'instance.query.check') {
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

        $rawStatus = is_string($output['status'] ?? null) ? strtolower((string) $output['status']) : null;
        $status = match ($resultStatus) {
            JobResultStatus::Succeeded => match ($rawStatus) {
                'online', 'running', 'up' => 'running',
                'starting', 'booting' => 'starting',
                'offline', 'stopped', 'down' => 'offline',
                default => 'unknown',
            },
            JobResultStatus::Failed => 'unknown',
            JobResultStatus::Cancelled => 'unknown',
        };

        if ($this->shouldQueueA2sFallbackAttempt($resultStatus, $payload, $output)) {
            $this->queueA2sFallbackAttempt($instance, $payload, $completedAt);
            return;
        }

        $cache = $instance->getQueryStatusCache();
        $cache['status'] = $status;
        $cache['players'] = $resultStatus === JobResultStatus::Succeeded && is_numeric($output['players'] ?? null)
            ? (int) $output['players']
            : null;
        $cache['max_players'] = $resultStatus === JobResultStatus::Succeeded && is_numeric($output['max_players'] ?? null)
            ? (int) $output['max_players']
            : null;
        $cache['message'] = is_string($output['message'] ?? null) ? $output['message'] : null;
        $cache['map'] = $resultStatus === JobResultStatus::Succeeded && is_string($output['map'] ?? null)
            ? (string) $output['map']
            : null;
        $cache['version'] = $resultStatus === JobResultStatus::Succeeded && is_string($output['version'] ?? null)
            ? (string) $output['version']
            : null;
        $cache['motd'] = $resultStatus === JobResultStatus::Succeeded && is_string($output['motd'] ?? null)
            ? (string) $output['motd']
            : null;
        $cache['latency_ms'] = is_numeric($output['latency_ms'] ?? null) ? (int) $output['latency_ms'] : null;
        $cache['result'] = QueryResultNormalizer::fromAgentOutput($output, $payload['query_type'] ?? null, $resultStatus);
        $cache['checked_at'] = $completedAt->format(DATE_RFC3339);
        unset($cache['queued_at']);

        $instance->setQueryStatusCache($cache);
        $instance->setQueryCheckedAt($completedAt);
        $this->entityManager->persist($instance);

        $this->auditLogger->log(null, 'instance.query.checked', [
            'instance_id' => $instance->getId(),
            'job_id' => $job->getId(),
            'agent_id' => $agentId,
            'status' => $status,
        ]);
    }


    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $output
     */
    private function shouldQueueA2sFallbackAttempt(JobResultStatus $resultStatus, array $payload, array $output): bool
    {
        $queryType = strtolower((string) ($payload['query_type'] ?? ''));
        if (!in_array($queryType, ['steam_a2s', 'a2s'], true)) {
            return false;
        }

        if (($payload['fallback_attempted'] ?? false) === true) {
            return false;
        }

        $queryPort = is_numeric($payload['query_port'] ?? null) ? (int) $payload['query_port'] : null;
        $gamePort = is_numeric($payload['game_port'] ?? null) ? (int) $payload['game_port'] : null;
        if ($queryPort === null || $gamePort === null || $queryPort === $gamePort) {
            return false;
        }

        if (!$this->outputContainsUnreachableError($output)) {
            return false;
        }

        return in_array($resultStatus, [JobResultStatus::Failed, JobResultStatus::Succeeded, JobResultStatus::Cancelled], true);
    }

    /**
     * @param array<string, mixed> $output
     */
    private function outputContainsUnreachableError(array $output): bool
    {
        $errorCode = strtolower((string) ($output['error_code'] ?? ''));
        if (in_array($errorCode, ['query_unreachable', 'query_timeout'], true)) {
            return true;
        }

        $encoded = strtolower(json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        return str_contains($encoded, 'query_unreachable')
            || str_contains($encoded, 'query_timeout')
            || str_contains($encoded, 'connection refused')
            || str_contains($encoded, 'i/o timeout')
            || str_contains($encoded, 'timed out')
            || str_contains($encoded, 'unreachable');
    }


    /**
     * @param array<string, mixed> $payload
     */
    private function resolveFallbackQueryPort(array $payload, ?int $primaryQueryPort): ?int
    {
        $fallbackPorts = $payload['fallback_query_ports'] ?? null;
        if (is_array($fallbackPorts)) {
            foreach ($fallbackPorts as $candidate) {
                if (!is_numeric($candidate)) {
                    continue;
                }
                $port = (int) $candidate;
                if ($port < 1 || $port > 65535) {
                    continue;
                }
                if ($primaryQueryPort !== null && $port === $primaryQueryPort) {
                    continue;
                }

                return $port;
            }
        }

        return is_numeric($payload['game_port'] ?? null) ? (int) $payload['game_port'] : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function queueA2sFallbackAttempt(Instance $instance, array $payload, DateTimeImmutable $completedAt): void
    {
        $fallbackPayload = $payload;
        $primaryQueryPort = is_numeric($payload['query_port'] ?? null) ? (int) $payload['query_port'] : null;
        $fallbackQueryPort = $this->resolveFallbackQueryPort($payload, $primaryQueryPort);

        if ($primaryQueryPort === null || $fallbackQueryPort === null || $primaryQueryPort === $fallbackQueryPort) {
            return;
        }

        $fallbackPayload['query_port'] = (string) $fallbackQueryPort;
        $fallbackPayload['fallback_attempted'] = true;
        $fallbackPayload['fallback_from_query_port'] = (string) $primaryQueryPort;

        if (!is_array($fallbackPayload['config'] ?? null)) {
            $fallbackPayload['config'] = [];
        }
        $fallbackPayload['config']['fallback_attempted'] = true;

        $this->entityManager->persist(new Job('instance.query.check', $fallbackPayload));

        $cache = $instance->getQueryStatusCache();
        $cache['status'] = 'queued';
        $cache['queued_at'] = $completedAt->format(DATE_ATOM);
        $cache['message'] = 'Primary query port unreachable, retrying with fallback port.';
        $instance->setQueryStatusCache($cache);
        $this->entityManager->persist($instance);

        $this->auditLogger->log(null, 'instance.query.retry_queued', [
            'instance_id' => $instance->getId(),
            'query_type' => $payload['query_type'] ?? null,
            'from_port' => $primaryQueryPort,
            'to_port' => $fallbackQueryPort,
        ]);
    }

    private function applyBackupUpdatesFromJob(
        \App\Module\Core\Domain\Entity\Job $job,
        JobResultStatus $resultStatus,
        string $agentId,
        array $output,
        DateTimeImmutable $completedAt,
    ): void {
        if (!in_array($job->getType(), ['instance.backup.create', 'instance.backup.restore'], true)) {
            return;
        }

        $payload = $job->getPayload();
        $backupId = $payload['backup_id'] ?? null;
        if (!is_int($backupId) && !is_string($backupId)) {
            return;
        }

        $backup = $this->backupRepository->find((int) $backupId);
        if ($backup === null) {
            return;
        }

        if ($job->getType() === 'instance.backup.create') {
            $status = $resultStatus === JobResultStatus::Succeeded ? BackupStatus::Succeeded : BackupStatus::Failed;
            $backup->markStatus($status, $completedAt);

            if ($resultStatus === JobResultStatus::Succeeded) {
                $sizeBytes = is_numeric($output['size_bytes'] ?? null) ? (int) $output['size_bytes'] : null;
                $checksum = is_string($output['sha256'] ?? null) ? trim((string) $output['sha256']) : null;
                $archivePath = is_string($output['backup_path'] ?? null) ? trim((string) $output['backup_path']) : null;

                $backup->setSizeBytes($sizeBytes);
                $backup->setChecksumSha256($checksum !== '' ? $checksum : null);
                $backup->setArchivePath($archivePath !== '' ? $archivePath : null);
                $backup->setError(null, null);

                $this->enforceBackupRetention($backup->getDefinition(), $backup);
            } else {
                $errorCode = is_string($output['error_code'] ?? null) ? (string) $output['error_code'] : 'backup_create_failed';
                $errorMessage = is_string($output['error'] ?? null) ? (string) $output['error'] : 'Backup creation failed.';
                $backup->setError($errorCode, $errorMessage);
            }

            $this->entityManager->persist($backup);

            $this->auditLogger->log(null, 'instance.backup.completed', [
                'backup_id' => $backup->getId(),
                'definition_id' => $backup->getDefinition()->getId(),
                'job_id' => $job->getId(),
                'agent_id' => $agentId,
                'status' => $status->value,
                'size_bytes' => $backup->getSizeBytes(),
                'checksum_sha256' => $backup->getChecksumSha256(),
                'error_code' => $backup->getErrorCode(),
            ]);

            return;
        }

        $this->auditLogger->log(null, 'instance.backup.restore_completed', [
            'backup_id' => $backup->getId(),
            'definition_id' => $backup->getDefinition()->getId(),
            'job_id' => $job->getId(),
            'agent_id' => $agentId,
            'status' => $resultStatus->value,
            'error_code' => is_string($output['error_code'] ?? null) ? $output['error_code'] : null,
        ]);
    }

    private function enforceBackupRetention(\App\Module\Core\Domain\Entity\BackupDefinition $definition, \App\Module\Core\Domain\Entity\Backup $keepBackup): void
    {
        $schedule = $definition->getSchedule();
        if ($schedule === null) {
            return;
        }

        $backups = $this->backupRepository->findByDefinition($definition);
        $now = new DateTimeImmutable();

        $retentionCount = max(0, $schedule->getRetentionCount());
        $retentionDays = max(0, $schedule->getRetentionDays());
        $cutoff = $retentionDays > 0 ? $now->modify(sprintf('-%d days', $retentionDays)) : null;

        $keptSucceeded = 0;
        foreach ($backups as $entry) {
            if ($entry->getId() === $keepBackup->getId()) {
                $keptSucceeded++;
                continue;
            }

            if ($entry->getStatus() !== BackupStatus::Succeeded) {
                continue;
            }

            $deleteForAge = $cutoff !== null && ($entry->getCompletedAt() ?? $entry->getCreatedAt()) < $cutoff;
            $deleteForCount = $retentionCount > 0 && $keptSucceeded >= $retentionCount;

            if ($deleteForAge || $deleteForCount) {
                $this->entityManager->remove($entry);
                continue;
            }

            $keptSucceeded++;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function resolveBackupTargetPayload(string $jobType, array $payload): array
    {
        if (!in_array($jobType, ['instance.backup.create', 'instance.backup.restore'], true)) {
            return $payload;
        }

        $definition = null;
        if ($jobType === 'instance.backup.create') {
            $definitionId = $payload['definition_id'] ?? null;
            if (is_int($definitionId) || is_string($definitionId)) {
                $definition = $this->backupDefinitionRepository->find((int) $definitionId);
            }
        }

        if ($jobType === 'instance.backup.restore') {
            $backupId = $payload['backup_id'] ?? null;
            if (is_int($backupId) || is_string($backupId)) {
                $backup = $this->backupRepository->find((int) $backupId);
                $definition = $backup?->getDefinition();
            }
        }

        if (!$definition instanceof \App\Module\Core\Domain\Entity\BackupDefinition) {
            return $payload;
        }

        $target = null;
        $payloadTargetId = $payload['backup_target_id'] ?? null;
        if ((is_int($payloadTargetId) || is_string($payloadTargetId)) && (string) $payloadTargetId !== '') {
            $target = $this->backupTargetRepository->find((int) $payloadTargetId);
        }

        if (!$target instanceof \App\Module\Core\Domain\Entity\BackupTarget) {
            $target = $definition->getBackupTarget();
        }

        if ($target === null || !$target->isEnabled()) {
            $payload['backup_target_type'] = 'local';
            return $payload;
        }

        $config = $target->getConfig();
        $payload['backup_target_id'] = (string) $target->getId();
        $payload['backup_target_type'] = $target->getType()->value;
        $payload['backup_target_config'] = $config;

        if (in_array($target->getType()->value, ['webdav', 'nextcloud'], true)) {
            $encrypted = $target->getEncryptedCredentials();
            if (is_string($encrypted['password'] ?? null) && $encrypted['password'] !== '') {
                $payload['backup_target_secret'] = [
                    'password' => $this->encryptionService->decrypt((string) $encrypted['password']),
                ];
            }
        }

        return $payload;
    }


    private function applyVoiceUpdatesFromJob(
        \App\Module\Core\Domain\Entity\Job $job,
        JobResultStatus $resultStatus,
        string $agentId,
        array $output,
        DateTimeImmutable $completedAt,
    ): void {
        if (!in_array($job->getType(), ['voice.probe', 'voice.action.start', 'voice.action.stop', 'voice.action.restart'], true)) {
            return;
        }

        $voiceInstanceId = (int) ($job->getPayload()['voice_instance_id'] ?? 0);
        if ($voiceInstanceId <= 0) {
            return;
        }

        $instance = $this->voiceInstanceRepository->find($voiceInstanceId);
        if ($instance === null) {
            return;
        }

        if ($job->getType() === 'voice.probe') {
            $status = $resultStatus === JobResultStatus::Succeeded ? (string) ($output['status'] ?? 'unknown') : 'unknown';
            $instance->updateStatus(
                strtolower($status),
                is_numeric($output['players_online'] ?? null) ? (int) $output['players_online'] : null,
                is_numeric($output['players_max'] ?? null) ? (int) $output['players_max'] : null,
                $resultStatus === JobResultStatus::Failed ? ((string) ($output['message'] ?? 'Probe failed.')) : null,
                $resultStatus === JobResultStatus::Failed ? ((string) ($output['error_code'] ?? 'voice_query_failed')) : null,
                $completedAt,
            );
        } else {
            if ($resultStatus === JobResultStatus::Failed) {
                $instance->updateStatus(
                    $instance->getStatus(),
                    $instance->getPlayersOnline(),
                    $instance->getPlayersMax(),
                    (string) ($output['message'] ?? 'Action failed.'),
                    (string) ($output['error_code'] ?? 'voice_action_failed'),
                    $completedAt,
                );
            }
        }

        $this->entityManager->persist($instance);
        $this->auditLogger->log(null, 'voice.job_applied', [
            'voice_instance_id' => $instance->getId(),
            'job_id' => $job->getId(),
            'agent_id' => $agentId,
            'job_type' => $job->getType(),
            'status' => $resultStatus->value,
        ]);
    }

    private function applyGdprAnonymizationFromJob(\App\Module\Core\Domain\Entity\Job $job, JobResultStatus $resultStatus, string $agentId): void
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

    private function resolveInstanceDir(\App\Module\Core\Domain\Entity\Instance $instance): string
    {
        if ($this->filesystemResolver instanceof GameServerPathResolver) {
            return $this->filesystemResolver->resolveRoot($instance);
        }

        if ($this->filesystemResolver instanceof InstanceFilesystemResolver) {
            // Backward compatibility for stale compiled containers during rolling deploys.
            return $this->filesystemResolver->resolveInstanceDir($instance);
        }

        throw new \RuntimeException('Unsupported filesystem resolver implementation.');
    }

}
