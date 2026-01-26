<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Api;

use App\Module\Core\Domain\Entity\DdosPolicy;
use App\Module\Core\Domain\Entity\DdosStatus;
use App\Module\Core\Domain\Entity\JobResult;
use App\Module\Core\Domain\Entity\MetricSample;
use App\Module\Core\Domain\Enum\BackupStatus;
use App\Module\Core\Domain\Enum\JobResultStatus;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Repository\AgentRepository;
use App\Repository\BackupRepository;
use App\Repository\DdosPolicyRepository;
use App\Repository\DdosStatusRepository;
use App\Repository\DomainRepository;
use App\Repository\GdprDeletionRequestRepository;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use App\Repository\PublicServerRepository;
use App\Repository\Ts3InstanceRepository;
use App\Repository\Ts6InstanceRepository;
use App\Repository\UserRepository;
use App\Module\Core\Application\AgentSignatureVerifier;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\FirewallStateManager;
use App\Module\Core\Application\GdprAnonymizer;
use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Core\Application\JobLogger;
use App\Module\Core\Application\NotificationService;
use App\Module\Core\Application\NodeDiskProtectionService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AgentApiController
{
    private const WINDOWS_SAFE_JOB_TYPES = [
        'agent.update',
        'agent.self_update',
        'agent.diagnostics',
    ];

    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly JobRepository $jobRepository,
        private readonly DomainRepository $domainRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly PublicServerRepository $publicServerRepository,
        private readonly Ts3InstanceRepository $ts3InstanceRepository,
        private readonly Ts6InstanceRepository $ts6InstanceRepository,
        private readonly UserRepository $userRepository,
        private readonly GdprDeletionRequestRepository $gdprDeletionRequestRepository,
        private readonly BackupRepository $backupRepository,
        private readonly DdosPolicyRepository $ddosPolicyRepository,
        private readonly DdosStatusRepository $ddosStatusRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly AgentSignatureVerifier $signatureVerifier,
        private readonly AuditLogger $auditLogger,
        private readonly FirewallStateManager $firewallStateManager,
        private readonly GdprAnonymizer $gdprAnonymizer,
        private readonly NotificationService $notificationService,
        private readonly NodeDiskProtectionService $nodeDiskProtectionService,
        private readonly InstanceFilesystemResolver $filesystemResolver,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly JobLogger $jobLogger,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%app.windows_nodes_enabled%')]
        private readonly bool $windowsNodesEnabled,
    ) {
    }

    #[Route(path: '/agent/heartbeat', name: 'agent_heartbeat', methods: ['POST'])]
    #[Route(path: '/api/v1/agent/heartbeat', name: 'agent_heartbeat_v1', methods: ['POST'])]
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

        if ($this->isWindowsStats($stats) && !$this->windowsNodesEnabled) {
            throw new ServiceUnavailableHttpException(null, 'Windows nodes are currently disabled.');
        }

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
    #[Route(path: '/api/v1/agent/jobs', name: 'agent_jobs_v1', methods: ['GET'])]
    public function jobs(Request $request): JsonResponse
    {
        $agent = $this->requireAgent($request);
        $now = new DateTimeImmutable();
        $this->expireStaleJobs($now);

        $jobs = $this->jobRepository->findQueuedForDispatch(20);
        $jobPayloads = [];
        $updateJobTypes = ['sniper.update', 'agent.update', 'agent.self_update'];
        $maxUpdateJobsPerAgent = 2;
        $runningUpdateJobs = $this->jobRepository->countRunningByAgentAndTypes($agent->getId(), $updateJobTypes);
        $maxConcurrency = $agent->getJobConcurrency();
        $runningJobs = $this->jobRepository->countRunningByAgent($agent->getId());
        $availableSlots = max(0, $maxConcurrency - $runningJobs);
        $isWindowsAgent = $this->isWindowsAgent($agent);

        if ($isWindowsAgent && !$this->windowsNodesEnabled) {
            throw new ServiceUnavailableHttpException(null, 'Windows nodes are currently disabled.');
        }

        if ($availableSlots === 0) {
            return new JsonResponse([
                'jobs' => [],
                'max_concurrency' => $maxConcurrency,
            ]);
        }

        $dispatched = 0;
        foreach ($jobs as $job) {
            if ($dispatched >= $availableSlots) {
                break;
            }
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

            if ($isWindowsAgent && !in_array($job->getType(), self::WINDOWS_SAFE_JOB_TYPES, true)) {
                continue;
            }

            $lockToken = bin2hex(random_bytes(16));
            $job->lock($agent->getId(), $lockToken, $now->modify('+10 minutes'));
            $job->transitionTo(JobStatus::Running);
            $this->jobLogger->log($job, 'Job started.', 10);

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

        return new JsonResponse([
            'jobs' => $jobPayloads,
            'max_concurrency' => $maxConcurrency,
        ]);
    }

    #[Route(path: '/agent/jobs/{id}/result', name: 'agent_job_result', methods: ['POST'])]
    #[Route(path: '/api/v1/agent/jobs/{id}/result', name: 'agent_job_result_v1', methods: ['POST'])]
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

        $this->appendJobLogsFromOutput($job, $output);

        $jobResult = new JobResult($job, $resultStatus, $output, $completedAt);
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

        $this->entityManager->persist($jobResult);
        $this->applyDomainUpdatesFromJob($job, $resultStatus, $agent->getId(), $output);
        if ($resultStatus === JobResultStatus::Succeeded) {
            $this->firewallStateManager->applyFirewallJobResult($job, $agent, $output);
        }
        $this->applyDdosStatusFromJob($job, $resultStatus, $agent, $output, $completedAt);
        $this->applyDdosPolicyFromJob($job, $resultStatus, $agent, $output, $completedAt);
        $this->applyTs3UpdatesFromJob($job, $resultStatus, $agent->getId(), $output);
        $this->applyTs6UpdatesFromJob($job, $resultStatus, $agent->getId(), $output);
        $this->applyInstanceUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $completedAt);
        $this->applyDiskUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $completedAt);
        $this->applyPublicServerUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $completedAt);
        $this->applyInstanceQueryUpdatesFromJob($job, $resultStatus, $agent->getId(), $output, $completedAt);
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

        if ($job->getLockExpiresAt() === null || $job->getLockExpiresAt() <= new DateTimeImmutable()) {
            throw new ConflictHttpException('Job lock expired.');
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

    private function requireAgent(Request $request): \App\Module\Core\Domain\Entity\Agent
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

    private function expireStaleJobs(DateTimeImmutable $now): void
    {
        $staleJobs = $this->jobRepository->findRunningWithExpiredLock($now);
        if ($staleJobs === []) {
            return;
        }

        foreach ($staleJobs as $job) {
            if ($job->getStatus() !== JobStatus::Running) {
                continue;
            }
            $job->transitionTo(JobStatus::Failed);
            $job->clearLock();
            $this->jobLogger->log($job, 'Job lock expired.', 100);
        }

        $this->entityManager->flush();
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
                'instance.create', 'instance.start', 'instance.restart', 'instance.reinstall', 'sniper.install' => \App\Module\Core\Domain\Enum\InstanceStatus::Running,
                'instance.stop' => \App\Module\Core\Domain\Enum\InstanceStatus::Stopped,
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
            if (in_array($job->getStatus(), [JobStatus::Queued, JobStatus::Running], true)) {
                return;
            }
        }

        $payload = [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'instance_dir' => $this->filesystemResolver->resolveInstanceDir($instance),
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

        $status = match ($resultStatus) {
            JobResultStatus::Succeeded => is_string($output['status'] ?? null) ? strtolower((string) $output['status']) : 'online',
            JobResultStatus::Failed => 'error',
            JobResultStatus::Cancelled => 'unknown',
        };

        $cache = $instance->getQueryStatusCache();
        $cache['status'] = $status;
        $cache['players'] = is_numeric($output['players'] ?? null) ? (int) $output['players'] : null;
        $cache['max_players'] = is_numeric($output['max_players'] ?? null) ? (int) $output['max_players'] : null;
        $cache['message'] = is_string($output['message'] ?? null) ? $output['message'] : null;
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

    private function applyBackupUpdatesFromJob(
        \App\Module\Core\Domain\Entity\Job $job,
        JobResultStatus $resultStatus,
        string $agentId,
        array $output,
        DateTimeImmutable $completedAt,
    ): void {
        if ($job->getType() !== 'instance.backup.create') {
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

        $status = $resultStatus === JobResultStatus::Succeeded ? BackupStatus::Succeeded : BackupStatus::Failed;
        $backup->markStatus($status, $completedAt);
        $this->entityManager->persist($backup);

        $this->auditLogger->log(null, 'instance.backup.completed', [
            'backup_id' => $backup->getId(),
            'definition_id' => $backup->getDefinition()->getId(),
            'job_id' => $job->getId(),
            'agent_id' => $agentId,
            'status' => $status->value,
            'output' => $output,
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
}
