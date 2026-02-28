<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Api;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceSftpCredential;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use App\Repository\InstanceRepository;
use App\Repository\InstanceSftpCredentialRepository;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class InstanceSftpCredentialApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceSftpCredentialRepository $instanceSftpCredentialRepository,
        private readonly JobRepository $jobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly AgentGameServerClient $agentGameServerClient,
        private readonly AppSettingsService $settingsService,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/api/instances/{id}/sftp-credentials', name: 'instances_sftp_credentials_show', methods: ['GET'])]
    #[Route(path: '/api/instances/{id}/access/health', name: 'instances_access_health', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/sftp-credentials', name: 'instances_sftp_credentials_show_v1', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/access/health', name: 'instances_access_health_v1', methods: ['GET'])]
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
                if ($this->isProvisioningPending($job)) {
                    return new JsonResponse([
                        'error_code' => 'sftp_provisioning_pending',
                        'message' => 'SFTP provisioning is already in progress.',
                        'job' => $jobSummary,
                        'agent' => $this->normalizeAgentState($instance),
                        'request_id' => $this->getRequestId($request),
                    ], JsonResponse::HTTP_ACCEPTED);
                }

                $username = $this->buildUsername($instance);
                $expiresAt = (new \DateTimeImmutable('+15 minutes'))->setTimezone(new \DateTimeZone('UTC'));
                $generatedPassword = bin2hex(random_bytes(18));
                $credential = new InstanceSftpCredential($instance, $username, $this->encryptionService->encrypt($generatedPassword));
                $credential->setRotatedAt(null);
                $credential->setExpiresAt($expiresAt);
                $credential->setRevealedAt(null);
                $this->entityManager->persist($credential);

                $backendPreference = $this->resolvePreferredBackend($instance);
                $response = $this->agentGameServerClient->provisionInstanceAccess($instance, [
                    'username' => $username,
                    'password' => $generatedPassword,
                    'root_path' => $this->resolveInstanceRootPath($instance),
                    'preferred_backend' => $backendPreference,
                    'host' => $this->resolveHost($instance),
                ]);
                if (($response['ok'] ?? false) !== true) {
                    $credential->setBackend('NONE');
                    $credential->setLastError(
                        is_string($response['error_code'] ?? null) ? (string) $response['error_code'] : 'INTERNAL_ERROR',
                        is_string($response['message'] ?? null) ? (string) $response['message'] : 'Provision failed.',
                    );
                } else {
                    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
                    $credential->setUsername(is_string($data['username'] ?? null) ? (string) $data['username'] : $username);
                    $credential->setBackend(is_string($data['backend'] ?? null) ? (string) $data['backend'] : 'NONE');
                    $credential->setHost(is_string($data['host'] ?? null) ? (string) $data['host'] : null);
                    $credential->setPort(is_numeric($data['port'] ?? null) ? (int) $data['port'] : null);
                    $credential->setRootPath(is_string($data['root_path'] ?? null) ? (string) $data['root_path'] : $this->resolveInstanceRootPath($instance));
                    $credential->setLastError(null, null);
                }
                $this->entityManager->flush();

                return $this->okResponse($request, [
                    'credential' => $this->normalizeCredential($credential),
                    'job' => null,
                    'agent' => $this->normalizeAgentState($instance),
                    'password_delivery' => [
                        'mode' => 'job_result',
                        'one_time' => true,
                    ],
                ], JsonResponse::HTTP_ACCEPTED);
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

            $this->syncCredentialHealth($instance, $credential);

            return $this->okResponse($request, [
                'credential' => $this->normalizeCredential($credential),
                'job' => $jobSummary,
                'agent' => $this->normalizeAgentState($instance),
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return $this->errorResponse(
                $request,
                $exception->getStatusCode(),
                $this->mapHttpErrorCode($exception),
                $exception->getMessage(),
            );
        } catch (TransportExceptionInterface $exception) {
            return $this->errorResponse(
                $request,
                JsonResponse::HTTP_GATEWAY_TIMEOUT,
                $this->mapTransportErrorCode($exception),
                'Unable to reach node agent. Please retry in a moment.',
            );
        } catch (\Throwable $exception) {
            $this->logger->error('instance.sftp.credentials.show_failed', [
                'request_id' => $this->getRequestId($request),
                'instance_id' => $id,
                'exception' => $exception,
            ]);

            return $this->errorResponse($request, JsonResponse::HTTP_INTERNAL_SERVER_ERROR, 'sftp_request_failed', 'Unable to load SFTP credentials.');
        }
    }

    #[Route(path: '/api/instances/{id}/sftp-credentials/reset', name: 'instances_sftp_credentials_reset', methods: ['POST'])]
    #[Route(path: '/api/instances/{id}/access/reset', name: 'instances_access_reset', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/sftp-credentials/reset', name: 'instances_sftp_credentials_reset_v1', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/access/reset', name: 'instances_access_reset_v1', methods: ['POST'])]
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

            if ($this->isProvisioningPending($job)) {
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
            $expiresAt = (new \DateTimeImmutable('+15 minutes'))->setTimezone(new \DateTimeZone('UTC'));
            $newPassword = bin2hex(random_bytes(18));

            if ($credential === null) {
                $credential = new InstanceSftpCredential($instance, $username, $this->encryptionService->encrypt($newPassword));
                $credential->setRotatedAt(null);
                $credential->setExpiresAt($expiresAt);
                $credential->setRevealedAt(null);
                $this->entityManager->persist($credential);
                $this->entityManager->flush();
            } else {
                $credential->setEncryptedPassword($this->encryptionService->encrypt($newPassword));
                $credential->setRevealedAt(null);
                $credential->setExpiresAt($expiresAt);
            }

            $backendPreference = $this->resolvePreferredBackend($instance);
            $agentResponse = $this->agentGameServerClient->resetInstanceAccess($instance, [
                'username' => $username,
                'password' => $newPassword,
                'root_path' => $this->resolveInstanceRootPath($instance),
                'preferred_backend' => $backendPreference,
                'host' => $this->resolveHost($instance),
            ]);
            if (($agentResponse['ok'] ?? false) !== true) {
                return $this->errorResponse(
                    $request,
                    JsonResponse::HTTP_CONFLICT,
                    is_string($agentResponse['error_code'] ?? null) ? (string) $agentResponse['error_code'] : 'INTERNAL_ERROR',
                    is_string($agentResponse['message'] ?? null) ? (string) $agentResponse['message'] : 'Access reset failed.',
                );
            }

            $credential->setEncryptedPassword($this->encryptionService->encrypt($newPassword));
            $credential->setExpiresAt($expiresAt);
            $credential->setRevealedAt(null);
            $agentData = is_array($agentResponse['data'] ?? null) ? $agentResponse['data'] : [];
            $credential->setUsername(is_string($agentData['username'] ?? null) ? (string) $agentData['username'] : $username);
            $credential->setBackend(is_string($agentData['backend'] ?? null) ? (string) $agentData['backend'] : $credential->getBackend());
            $credential->setHost(is_string($agentData['host'] ?? null) ? (string) $agentData['host'] : $credential->getHost());
            $credential->setPort(is_numeric($agentData['port'] ?? null) ? (int) $agentData['port'] : $credential->getPort());
            $credential->setRootPath(is_string($agentData['root_path'] ?? null) ? (string) $agentData['root_path'] : $credential->getRootPath());
            $credential->setLastError(null, null);
            $this->entityManager->flush();

            $response = $this->okResponse($request, [
                'credential' => $this->normalizeCredential($credential),
                'job' => null,
                'agent' => $this->normalizeAgentState($instance),
                'password_delivery' => [
                    'mode' => 'job_result',
                    'one_time' => true,
                ],
            ], JsonResponse::HTTP_ACCEPTED);

            $this->logger->info('instance.sftp.credentials.reset', [
                'request_id' => $this->getRequestId($request),
                'user_id' => $actor->getId(),
                'instance_id' => $instance->getId(),
                'customer_id' => $instance->getCustomer()->getId(),
                'status_code' => $response->getStatusCode(),
                'job_id' => null,
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
        } catch (TransportExceptionInterface $exception) {
            return $this->errorResponse(
                $request,
                JsonResponse::HTTP_GATEWAY_TIMEOUT,
                $this->mapTransportErrorCode($exception),
                'Unable to reach node agent. Please retry in a moment.',
            );
        } catch (\Throwable $exception) {
            $this->logger->error('instance.sftp.credentials.reset_failed', [
                'request_id' => $this->getRequestId($request),
                'instance_id' => $id,
                'exception' => $exception,
            ]);

            return $this->errorResponse($request, JsonResponse::HTTP_INTERNAL_SERVER_ERROR, 'sftp_request_failed', 'Unable to reset SFTP credentials.');
        }
    }

    #[Route(path: '/api/instances/{id}/access/reveal', name: 'instances_access_reveal', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/access/reveal', name: 'instances_access_reveal_v1', methods: ['POST'])]
    public function reveal(Request $request, int $id): JsonResponse
    {
        try {
            $actor = $this->requireUser($request);
            $instance = $this->findInstance($actor, $id);
            $credential = $this->instanceSftpCredentialRepository->findOneByInstance($instance);
            if ($credential === null) {
                return $this->errorResponse($request, JsonResponse::HTTP_NOT_FOUND, 'ACCESS_NOT_PROVISIONED', 'Access credential has not been provisioned yet.');
            }
            if ($credential->getRevealedAt() !== null) {
                return $this->errorResponse($request, JsonResponse::HTTP_CONFLICT, 'SECRET_ALREADY_VIEWED', 'Password was already revealed once. Use reset to generate a new one.');
            }
            if ($credential->getExpiresAt() !== null && $credential->getExpiresAt() < new \DateTimeImmutable()) {
                return $this->errorResponse($request, JsonResponse::HTTP_CONFLICT, 'SECRET_EXPIRED', 'Password reveal expired. Use reset to generate a new password.');
            }

            $password = $this->encryptionService->decrypt($credential->getEncryptedPassword());
            $credential->setRevealedAt(new \DateTimeImmutable());
            $this->entityManager->persist($credential);
            $this->entityManager->flush();

            return $this->okResponse($request, [
                'username' => $credential->getUsername(),
                'password' => $password,
                'backend' => $credential->getBackend(),
                'host' => $this->resolveHost($instance),
                'port' => $this->resolvePort($instance),
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return $this->errorResponse($request, $exception->getStatusCode(), $this->mapHttpErrorCode($exception), $exception->getMessage());
        } catch (\Throwable) {
            return $this->errorResponse($request, JsonResponse::HTTP_INTERNAL_SERVER_ERROR, 'INTERNAL_ERROR', 'Unable to reveal access credentials.');
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

    private function resolveInstanceRootPath(Instance $instance): string
    {
        $installPath = trim((string) $instance->getInstallPath());
        if ($installPath !== '' && str_starts_with($installPath, '/')) {
            if (preg_match('#^/gs[0-9a-z]+$#i', $installPath) === 1) {
                $baseDir = rtrim((string) $this->settingsService->getInstanceBaseDir(), '/');
                if ($baseDir !== '') {
                    return $baseDir . '/' . ltrim($installPath, '/');
                }
            }

            return $installPath;
        }

        $baseDir = rtrim((string) $this->settingsService->getInstanceBaseDir(), '/');
        $instanceId = (string) ($instance->getId() ?? 'instance');

        return ($baseDir !== '' ? $baseDir : '/srv') . '/gs' . preg_replace('/[^a-z0-9]/i', '', $instanceId);
    }

    private function normalizeCredential(InstanceSftpCredential $credential): array
    {
        $instance = $credential->getInstance();
        $data = [
            'instance_id' => $credential->getInstance()->getId(),
            'username' => $credential->getUsername(),
            'backend' => $credential->getBackend(),
            'host' => $this->resolveHost($instance),
            'port' => $this->resolvePort($instance),
            'root_path' => $credential->getRootPath() ?: $this->resolveInstanceRootPath($instance),
            'password_revealed' => $credential->getRevealedAt() !== null,
            'password_masked' => $this->maskPassword(),
            'rotated_at' => $credential->getRotatedAt()?->format(DATE_RFC3339),
            'expires_at' => $credential->getExpiresAt()?->format(DATE_RFC3339),
            'updated_at' => $credential->getUpdatedAt()->format(DATE_RFC3339),
            'last_error_code' => $credential->getLastErrorCode(),
            'last_error_message' => $credential->getLastErrorMessage(),
        ];

        return $data;
    }

    private function queueResetJob(Request $request, User $actor, Instance $instance, InstanceSftpCredential $credential, string $username, string $password, \DateTimeImmutable $expiresAt): Job
    {
        $job = new Job('instance.sftp.credentials.reset', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'credential_id' => $credential->getId(),
            'username' => $username,
            'password' => $password,
            'root_path' => $this->resolveInstanceRootPath($instance),
            'preferred_backend' => strtoupper(PHP_OS_FAMILY) === 'WINDOWS' ? 'WINDOWS_OPENSSH_SFTP' : 'PROFTPD_SFTP',
            'rotate' => true,
            'expires_at' => $expiresAt->format(DATE_RFC3339),
            'request_id' => $this->getRequestId($request),
        ]);
        $this->entityManager->persist($job);

        $this->auditLogger->log($actor, 'instance.sftp.credentials.reset_requested', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'job_id' => $job->getId(),
            'username' => $username,
            'credential_id' => $credential->getId(),
            'rotate' => true,
        ]);

        return $job;
    }

    private function syncCredentialHealth(Instance $instance, InstanceSftpCredential $credential): void
    {
        try {
            $health = $this->agentGameServerClient->getInstanceAccessHealth($instance);
        } catch (\Throwable $exception) {
            $credential->setBackend('NONE');
            $credential->setLastError('AGENT_UNREACHABLE', $exception->getMessage());
            $this->entityManager->persist($credential);
            $this->entityManager->flush();

            return;
        }

        if (($health['ok'] ?? false) !== true) {
            $credential->setBackend('NONE');
            $credential->setLastError(
                is_string($health['error_code'] ?? null) ? (string) $health['error_code'] : 'INTERNAL_ERROR',
                is_string($health['message'] ?? null) ? (string) $health['message'] : 'Access backend unavailable.',
            );
            $this->entityManager->persist($credential);
            $this->entityManager->flush();

            return;
        }

        $data = is_array($health['data'] ?? null) ? $health['data'] : [];
        $credential->setUsername(is_string($data['username'] ?? null) ? (string) $data['username'] : $credential->getUsername());
        $credential->setBackend(is_string($data['backend'] ?? null) ? (string) $data['backend'] : 'NONE');
        $credential->setHost(is_string($data['host'] ?? null) ? (string) $data['host'] : $credential->getHost());
        $credential->setPort(is_numeric($data['port'] ?? null) ? (int) $data['port'] : $credential->getPort());
        $credential->setRootPath(is_string($data['root_path'] ?? null) ? (string) $data['root_path'] : $credential->getRootPath());
        $credential->setLastError(null, null);
        $this->entityManager->persist($credential);
        $this->entityManager->flush();
    }

    private function resolvePreferredBackend(Instance $instance): string
    {
        try {
            $capabilities = $this->agentGameServerClient->getAccessCapabilities($instance);
        } catch (\Throwable) {
            return strtoupper(PHP_OS_FAMILY) === 'WINDOWS' ? 'WINDOWS_OPENSSH_SFTP' : 'PROFTPD_SFTP';
        }

        $data = is_array($capabilities['data'] ?? null) ? $capabilities['data'] : [];
        $supported = is_array($data['supported_backends'] ?? null) ? $data['supported_backends'] : [];
        foreach ($supported as $backend) {
            if (!is_string($backend)) {
                continue;
            }
            $normalized = strtoupper(trim($backend));
            if ($normalized !== '' && $normalized !== 'NONE') {
                return $normalized;
            }
        }

        return strtoupper(PHP_OS_FAMILY) === 'WINDOWS' ? 'WINDOWS_OPENSSH_SFTP' : 'PROFTPD_SFTP';
    }

    private function findLatestJob(int $instanceId): ?Job
    {
        return $this->jobRepository->findLatestByTypeAndInstanceId('instance.sftp.credentials.reset', $instanceId);
    }


    private function isProvisioningPending(?Job $job): bool
    {
        if ($job === null) {
            return false;
        }

        return in_array($job->getStatus(), [
            \App\Module\Core\Domain\Enum\JobStatus::Queued,
            \App\Module\Core\Domain\Enum\JobStatus::Claimed,
            \App\Module\Core\Domain\Enum\JobStatus::Running,
        ], true);
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

    private function okResponse(Request $request, array $data, int $statusCode = JsonResponse::HTTP_OK): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'data' => $data,
            'request_id' => $this->getRequestId($request),
        ], $statusCode);
    }

    private function errorResponse(Request $request, int $statusCode, string $errorCode, string $message, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
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

    private function mapTransportErrorCode(TransportExceptionInterface $exception): string
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timeout') ? 'sftp_agent_timeout' : 'sftp_agent_unreachable';
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
