<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Instance;
use App\Entity\InstanceSftpCredential;
use App\Entity\Job;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\InstanceRepository;
use App\Repository\InstanceSftpCredentialRepository;
use App\Service\AuditLogger;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class InstanceSftpCredentialApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceSftpCredentialRepository $instanceSftpCredentialRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route(path: '/api/instances/{id}/sftp-credentials', name: 'instances_sftp_credentials_show', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/sftp-credentials', name: 'instances_sftp_credentials_show_v1', methods: ['GET'])]
    public function show(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $instance = $this->findInstance($actor, $id);
        $credential = $this->instanceSftpCredentialRepository->findOneByInstance($instance);

        $includePassword = filter_var($request->query->get('include_password', false), FILTER_VALIDATE_BOOLEAN);
        $allowedUserTypes = [UserType::Admin, UserType::Customer];
        $canManageCredentials = in_array($actor->getType(), $allowedUserTypes, true);
        $generatedPassword = null;
        if ($credential === null && $canManageCredentials) {
            $username = $this->buildUsername($instance);
            $generatedPassword = $this->generatePassword();
            $encryptedPassword = $this->encryptionService->encrypt($generatedPassword);

            $credential = new InstanceSftpCredential($instance, $username, $encryptedPassword);
            $this->entityManager->persist($credential);

            $this->queueResetJob($actor, $instance, $username, $generatedPassword);
            $this->entityManager->flush();
        }

        if ($credential === null) {
            return new JsonResponse(['error' => 'SFTP credentials not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $allowPassword = $includePassword && $canManageCredentials;
        $password = null;
        if ($allowPassword && $generatedPassword !== null) {
            $password = $generatedPassword;
        } elseif ($allowPassword) {
            try {
                $password = $this->encryptionService->decrypt($credential->getEncryptedPassword());
            } catch (\RuntimeException $exception) {
                return new JsonResponse(['error' => 'Unable to decrypt credentials.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return new JsonResponse([
            'credential' => $this->normalizeCredential($credential, $password),
        ]);
    }

    #[Route(path: '/api/instances/{id}/sftp-credentials/reset', name: 'instances_sftp_credentials_reset', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/sftp-credentials/reset', name: 'instances_sftp_credentials_reset_v1', methods: ['POST'])]
    public function reset(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $instance = $this->findInstance($actor, $id);

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

        $job = $this->queueResetJob($actor, $instance, $username, $password);

        $this->entityManager->flush();

        return new JsonResponse([
            'credential' => $this->normalizeCredential($credential, $password),
            'job_id' => $job->getId(),
        ]);
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

        if ($actor->getType() === UserType::Admin) {
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

    private function queueResetJob(User $actor, Instance $instance, string $username, string $password): Job
    {
        $job = new Job('instance.sftp.credentials.reset', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'username' => $username,
            'password' => $password,
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

        $env = $_ENV['EASYWI_SFTP_HOST'] ?? $_SERVER['EASYWI_SFTP_HOST'] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
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

        $env = $_ENV['EASYWI_SFTP_PORT'] ?? $_SERVER['EASYWI_SFTP_PORT'] ?? null;
        if (is_numeric($env)) {
            return max(1, (int) $env);
        }

        return 22;
    }
}
