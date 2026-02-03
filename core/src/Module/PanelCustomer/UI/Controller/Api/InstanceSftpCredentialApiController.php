<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Api;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceSftpCredential;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\InstanceRepository;
use App\Repository\InstanceSftpCredentialRepository;
use App\Repository\JobRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\AppSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class InstanceSftpCredentialApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceSftpCredentialRepository $instanceSftpCredentialRepository,
        private readonly JobRepository $jobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly AppSettingsService $settingsService,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/api/instances/{id}/sftp-credentials', name: 'instances_sftp_credentials_show', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/sftp-credentials', name: 'instances_sftp_credentials_show_v1', methods: ['GET'])]
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $startedAt = microtime(true);
            $actor = $this->requireUser($request);
            if (!$actor->isAdmin() && !$actor->isCustomer()) {
                return $this->errorResponse($request, JsonResponse::HTTP_FORBIDDEN, 'sftp_forbidden', 'Not authorized to view SFTP credentials.');
            }

            $instance = $this->findInstance($actor, $id);
            $credential = $this->instanceSftpCredentialRepository->findOneByInstance($instance);
            $job = $this->findLatestJob($instance->getId());
            $jobSummary = $this->normalizeJobSummary($job);

            if ($credential === null) {
                $this->logger->info('instance.sftp.credentials.show', [
                    'request_id' => $this->getRequestId($request),
                    'user_id' => $actor->getId(),
                    'instance_id' => $instance->getId(),
                    'customer_id' => $instance->getCustomer()->getId(),
                    'status_code' => JsonResponse::HTTP_NOT_FOUND,
                    'job_id' => $job?->getId(),
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);

                return $this->errorResponse($request, JsonResponse::HTTP_NOT_FOUND, 'sftp_credentials_missing', 'SFTP credentials are not available yet.', [
                    'job' => $jobSummary,
                    'agent' => $this->normalizeAgentState($instance),
                ]);
            }

            $includePassword = filter_var($request->query->get('include_password', false), FILTER_VALIDATE_BOOLEAN);
            $allowPassword = $includePassword && ($actor->isAdmin() || $actor->getType() === UserType::Customer);
            $password = null;
            if ($allowPassword) {
                try {
                    $password = $this->encryptionService->decrypt($credential->getEncryptedPassword());
                } catch (\RuntimeException $exception) {
                    return $this->errorResponse($request, JsonResponse::HTTP_INTERNAL_SERVER_ERROR, 'sftp_decrypt_failed', 'Unable to decrypt credentials.');
                }
            }

            $this->logger->info('instance.sftp.credentials.show', [
                'request_id' => $this->getRequestId($request),
                'user_id' => $actor->getId(),
                'instance_id' => $instance->getId(),
                'customer_id' => $instance->getCustomer()->getId(),
                'status_code' => JsonResponse::HTTP_OK,
                'job_id' => $job?->getId(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return new JsonResponse([
                'credential' => $this->normalizeCredential($credential, $password),
                'job' => $jobSummary,
                'agent' => $this->normalizeAgentState($instance),
                'request_id' => $this->getRequestId($request),
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return $this->errorResponse(
                $request,
                $exception->getStatusCode(),
                $this->mapHttpErrorCode($exception),
                $exception->getMessage(),
            );
        }
    }

    #[Route(path: '/api/instances/{id}/sftp-credentials/reset', name: 'instances_sftp_credentials_reset', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/sftp-credentials/reset', name: 'instances_sftp_credentials_reset_v1', methods: ['POST'])]
    public function reset(Request $request, int $id): JsonResponse
    {
        try {
            $startedAt = microtime(true);
            $actor = $this->requireUser($request);
            if (!$actor->isAdmin() && !$actor->isCustomer()) {
                return $this->errorResponse($request, JsonResponse::HTTP_FORBIDDEN, 'sftp_forbidden', 'Not authorized to reset SFTP credentials.');
            }
            $instance = $this->findInstance($actor, $id);
            $job = $this->findLatestJob($instance->getId());

            if ($job !== null && in_array($job->getStatus(), [
                \App\Module\Core\Domain\Enum\JobStatus::Queued,
                \App\Module\Core\Domain\Enum\JobStatus::Claimed,
                \App\Module\Core\Domain\Enum\JobStatus::Running,
            ], true)) {
                return new JsonResponse([
                    'error_code' => 'sftp_provisioning_pending',
                    'message' => 'SFTP provisioning is already in progress.',
                    'job' => $this->normalizeJobSummary($job),
                    'agent' => $this->normalizeAgentState($instance),
                    'request_id' => $this->getRequestId($request),
                ], JsonResponse::HTTP_ACCEPTED);
            }

            $credential = $this->instanceSftpCredentialRepository->findOneByInstance($instance);
            $username = $credential?->getUsername() ?? $this->buildUsername($instance);
            $password = $this->generatePassword();
            $encryptedPassword = $this->encryptionService->encrypt($password);

            if ($credential === null) {
                $credential = new InstanceSftpCredential($instance, $username, $encryptedPassword);
            } else {
                $credential->setEncryptedPassword($encryptedPassword);
            }

            $this->entityManager->persist($credential);

            $job = $this->queueResetJob($request, $actor, $instance, $username, $password);
            $job->setMaxAttempts(3);

            $this->entityManager->flush();

            $response = new JsonResponse([
                'credential' => $this->normalizeCredential($credential, $password),
                'job' => $this->normalizeJobSummary($job),
                'agent' => $this->normalizeAgentState($instance),
                'request_id' => $this->getRequestId($request),
            ], JsonResponse::HTTP_ACCEPTED);

            $this->logger->info('instance.sftp.credentials.reset', [
                'request_id' => $this->getRequestId($request),
                'user_id' => $actor->getId(),
                'instance_id' => $instance->getId(),
                'customer_id' => $instance->getCustomer()->getId(),
                'status_code' => $response->getStatusCode(),
                'job_id' => $job->getId(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return $response;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return $this->errorResponse(
                $request,
                $exception->getStatusCode(),
                $this->mapHttpErrorCode($exception),
                $exception->getMessage(),
            );
        }
    }

    private function requireUser(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function findInstance(User $actor, int $id): Instance
    {
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Instance not found.');
        }

        if ($actor->isAdmin()) {
            return $instance;
        }

        if ($instance->getCustomer()->getId() !== $actor->getId()) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Forbidden.');
        }

        return $instance;
    }

    private function buildUsername(Instance $instance): string
    {
        return sprintf('sftp%d', $instance->getId());
    }

    private function generatePassword(): string
    {
        return bin2hex(random_bytes(12));
    }

    private function normalizeCredential(InstanceSftpCredential $credential, ?string $password = null): array
    {
        $instance = $credential->getInstance();
        $data = [
            'instance_id' => $credential->getInstance()->getId(),
            'username' => $credential->getUsername(),
            'host' => $this->resolveHost($instance),
            'port' => $this->resolvePort($instance),
            'password_masked' => $password === null ? $this->maskPassword() : $this->maskPassword($password),
            'updated_at' => $credential->getUpdatedAt()->format(DATE_RFC3339),
        ];

        if ($password !== null) {
            $data['password'] = $password;
        }

        return $data;
    }

    private function queueResetJob(Request $request, User $actor, Instance $instance, string $username, string $password): Job
    {
        $job = new Job('instance.sftp.credentials.reset', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'username' => $username,
            'password' => $password,
            'request_id' => $this->getRequestId($request),
        ]);
        $this->entityManager->persist($job);

        $this->auditLogger->log($actor, 'instance.sftp.credentials.reset_requested', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'job_id' => $job->getId(),
            'username' => $username,
        ]);

        return $job;
    }

    private function findLatestJob(int $instanceId): ?Job
    {
        return $this->jobRepository->findLatestByTypeAndInstanceId('instance.sftp.credentials.reset', $instanceId);
    }

    private function normalizeJobSummary(?Job $job): ?array
    {
        if ($job === null) {
            return null;
        }

        return [
            'id' => $job->getId(),
            'status' => $job->getStatus()->value,
            'attempts' => $job->getAttempts(),
            'max_attempts' => $job->getMaxAttempts(),
            'claimed_by' => $job->getClaimedBy(),
            'claimed_at' => $job->getClaimedAt()?->format(DATE_RFC3339),
            'last_error' => $job->getLastError(),
            'last_error_code' => $job->getLastErrorCode(),
            'last_attempt_at' => $job->getLastAttemptAt()?->format(DATE_RFC3339),
        ];
    }

    private function normalizeAgentState(Instance $instance): array
    {
        $agent = $instance->getNode();

        return [
            'id' => $agent->getId(),
            'status' => $agent->getStatus(),
            'last_seen_ip' => $agent->getLastHeartbeatIp(),
            'last_seen_at' => $agent->getLastHeartbeatAt()?->format(DATE_RFC3339),
        ];
    }

    /**
     * @param array<string, mixed> $details
     */
    private function errorResponse(Request $request, int $statusCode, string $errorCode, string $message, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'error_code' => $errorCode,
            'message' => $message,
            'request_id' => $this->getRequestId($request),
            'details' => $details,
        ], $statusCode);
    }

    private function getRequestId(Request $request): string
    {
        $requestId = $request->headers->get('X-Request-ID');
        if (is_string($requestId) && $requestId !== '') {
            return $requestId;
        }

        $attribute = $request->attributes->get('request_id');
        if (is_string($attribute) && $attribute !== '') {
            return $attribute;
        }

        return '';
    }

    private function mapHttpErrorCode(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception): string
    {
        return match (true) {
            $exception instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException => 'sftp_forbidden',
            $exception instanceof \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException => 'sftp_unauthorized',
            $exception instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException => 'sftp_not_found',
            default => 'sftp_request_failed',
        };
    }

    private function maskPassword(string $password = ''): string
    {
        $length = $password !== '' ? max(8, min(12, mb_strlen($password))) : 8;

        return str_repeat('*', $length);
    }

    private function resolveHost(Instance $instance): ?string
    {
        $metadata = $instance->getNode()->getMetadata();
        $host = is_array($metadata) ? ($metadata['sftp_host'] ?? null) : null;
        if (is_string($host) && $host !== '') {
            return $host;
        }

        $lastIp = $instance->getNode()->getLastHeartbeatIp();
        if ($lastIp !== null && $lastIp !== '') {
            return $lastIp;
        }

        $host = $this->settingsService->getSftpHost();
        if (is_string($host) && $host !== '') {
            return $host;
        }

        return null;
    }

    private function resolvePort(Instance $instance): int
    {
        $metadata = $instance->getNode()->getMetadata();
        $port = is_array($metadata) ? ($metadata['sftp_port'] ?? null) : null;
        if (is_numeric($port)) {
            return max(1, (int) $port);
        }

        return $this->settingsService->getSftpPort();
    }
}
