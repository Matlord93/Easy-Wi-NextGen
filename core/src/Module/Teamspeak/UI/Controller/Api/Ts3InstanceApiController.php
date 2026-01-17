<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\UI\Controller\Api;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\Ts3Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\Ts3DatabaseMode;
use App\Module\Core\Domain\Enum\Ts3InstanceStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\Ts3InstanceRepository;
use App\Repository\UserRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class Ts3InstanceApiController
{
    public function __construct(
        private readonly Ts3InstanceRepository $ts3InstanceRepository,
        private readonly UserRepository $userRepository,
        private readonly AgentRepository $agentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly EncryptionService $encryptionService,
    ) {
    }

    #[Route(path: '/api/ts3/instances', name: 'ts3_instances_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/ts3/instances', name: 'ts3_instances_list_v1', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);

        $instances = $actor->isAdmin()
            ? $this->ts3InstanceRepository->findBy([], ['updatedAt' => 'DESC'])
            : $this->ts3InstanceRepository->findByCustomer($actor);

        return new JsonResponse([
            'instances' => array_map(fn (Ts3Instance $instance) => $this->normalizeInstance($instance), $instances),
        ]);
    }

    #[Route(path: '/api/ts3/instances', name: 'ts3_instances_create', methods: ['POST'])]
    #[Route(path: '/api/v1/admin/ts3/instances', name: 'ts3_instances_create_v1', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);
        if (!$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $this->parseJsonPayload($request);
        $formData = $this->validatePayload($payload);
        if ($formData['error'] instanceof JsonResponse) {
            return $formData['error'];
        }

        $instance = new Ts3Instance(
            $formData['customer'],
            $formData['node'],
            $formData['name'],
            $formData['voice_port'],
            $formData['query_port'],
            $formData['file_port'],
            $formData['db_mode'],
            $formData['db_host'],
            $formData['db_port'],
            $formData['db_name'],
            $formData['db_username'],
            $formData['db_password'],
            Ts3InstanceStatus::Provisioning,
        );

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        $job = $this->queueTs3Job('ts3.create', $instance, [
            'name' => $instance->getName(),
            'voice_port' => (string) $instance->getVoicePort(),
            'query_port' => (string) $instance->getQueryPort(),
            'file_port' => (string) $instance->getFilePort(),
            'db_mode' => $instance->getDatabaseMode()->value,
            'db_host' => $instance->getDatabaseHost() ?? '',
            'db_port' => $instance->getDatabasePort() !== null ? (string) $instance->getDatabasePort() : '',
            'db_name' => $instance->getDatabaseName() ?? '',
            'db_username' => $instance->getDatabaseUsername() ?? '',
            'db_password' => $instance->getDatabasePassword() !== null ? json_encode($instance->getDatabasePassword()) : '',
        ]);

        $this->auditLogger->log($actor, 'ts3.instance_created', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'name' => $instance->getName(),
            'voice_port' => $instance->getVoicePort(),
            'query_port' => $instance->getQueryPort(),
            'file_port' => $instance->getFilePort(),
            'db_mode' => $instance->getDatabaseMode()->value,
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'instance' => $this->normalizeInstance($instance),
            'job_id' => $job->getId(),
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/ts3/instances/{id}/actions', name: 'ts3_instances_actions', methods: ['POST'])]
    #[Route(path: '/api/v1/admin/ts3/instances/{id}/actions', name: 'ts3_instances_actions_v1', methods: ['POST'])]
    public function action(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        if (!$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $instance = $this->ts3InstanceRepository->find($id);
        if ($instance === null) {
            return new JsonResponse(['error' => 'Instance not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $this->parseJsonPayload($request);
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        if ($action === '') {
            return new JsonResponse(['error' => 'Action is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $jobType = $this->actionToJobType($action);
        if ($jobType === null) {
            return new JsonResponse(['error' => 'Unsupported action.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $actionPayload = $this->buildActionPayload($action, $payload);
        if ($actionPayload['error'] instanceof JsonResponse) {
            return $actionPayload['error'];
        }

        $job = $this->queueTs3Job($jobType, $instance, $actionPayload['payload']);
        $this->auditLogger->log($actor, sprintf('ts3.instance_%s', $action), [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'action' => $action,
            'job_id' => $job->getId(),
            'payload' => $actionPayload['payload'],
        ]);
        $this->entityManager->flush();

        return new JsonResponse([
            'job_id' => $job->getId(),
            'instance' => $this->normalizeInstance($instance),
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

    private function parseJsonPayload(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\JsonException $exception) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid JSON payload.', $exception);
        }
    }

    private function validatePayload(array $payload): array
    {
        $customerId = $payload['customer_id'] ?? null;
        $nodeId = (string) ($payload['node_id'] ?? '');
        $name = trim((string) ($payload['name'] ?? ''));
        $voicePortValue = $payload['voice_port'] ?? null;
        $queryPortValue = $payload['query_port'] ?? null;
        $filePortValue = $payload['file_port'] ?? null;
        $dbModeValue = strtolower(trim((string) ($payload['db_mode'] ?? '')));
        $dbHost = trim((string) ($payload['db_host'] ?? ''));
        $dbPortValue = $payload['db_port'] ?? null;
        $dbName = trim((string) ($payload['db_name'] ?? ''));
        $dbUsername = trim((string) ($payload['db_username'] ?? ''));
        $dbPassword = trim((string) ($payload['db_password'] ?? ''));

        if (!is_numeric($customerId)) {
            return ['error' => new JsonResponse(['error' => 'Customer is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }
        if ($nodeId === '') {
            return ['error' => new JsonResponse(['error' => 'Node is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }
        if ($name === '') {
            return ['error' => new JsonResponse(['error' => 'Name is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $voicePort = $this->validatePort($voicePortValue, 'voice_port');
        if ($voicePort['error'] instanceof JsonResponse) {
            return $voicePort;
        }
        $queryPort = $this->validatePort($queryPortValue, 'query_port');
        if ($queryPort['error'] instanceof JsonResponse) {
            return $queryPort;
        }
        $filePort = $this->validatePort($filePortValue, 'file_port');
        if ($filePort['error'] instanceof JsonResponse) {
            return $filePort;
        }

        $dbMode = Ts3DatabaseMode::tryFrom($dbModeValue);
        if ($dbMode === null) {
            return ['error' => new JsonResponse(['error' => 'Invalid db_mode.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $dbPort = null;
        $encryptedPassword = null;
        if ($dbMode === Ts3DatabaseMode::Mysql) {
            if ($dbHost === '' || $dbName === '' || $dbUsername === '') {
                return ['error' => new JsonResponse(['error' => 'MySQL settings are required.'], JsonResponse::HTTP_BAD_REQUEST)];
            }
            if ($dbPortValue === null || $dbPortValue === '' || !is_numeric($dbPortValue)) {
                return ['error' => new JsonResponse(['error' => 'MySQL port must be numeric.'], JsonResponse::HTTP_BAD_REQUEST)];
            }
            $dbPort = (int) $dbPortValue;
            if ($dbPort <= 0 || $dbPort > 65535) {
                return ['error' => new JsonResponse(['error' => 'MySQL port must be between 1 and 65535.'], JsonResponse::HTTP_BAD_REQUEST)];
            }
            if ($dbPassword === '') {
                return ['error' => new JsonResponse(['error' => 'MySQL password is required.'], JsonResponse::HTTP_BAD_REQUEST)];
            }
            $encryptedPassword = $this->encryptionService->encrypt($dbPassword);
        }

        $customer = $this->userRepository->find((int) $customerId);
        if ($customer === null || $customer->getType() !== UserType::Customer) {
            return ['error' => new JsonResponse(['error' => 'Customer not found.'], JsonResponse::HTTP_NOT_FOUND)];
        }

        $node = $this->agentRepository->find($nodeId);
        if ($node === null) {
            return ['error' => new JsonResponse(['error' => 'Node not found.'], JsonResponse::HTTP_NOT_FOUND)];
        }

        return [
            'customer' => $customer,
            'node' => $node,
            'name' => $name,
            'voice_port' => $voicePort['port'],
            'query_port' => $queryPort['port'],
            'file_port' => $filePort['port'],
            'db_mode' => $dbMode,
            'db_host' => $dbMode === Ts3DatabaseMode::Mysql ? $dbHost : null,
            'db_port' => $dbPort,
            'db_name' => $dbMode === Ts3DatabaseMode::Mysql ? $dbName : null,
            'db_username' => $dbMode === Ts3DatabaseMode::Mysql ? $dbUsername : null,
            'db_password' => $encryptedPassword,
            'error' => null,
        ];
    }

    private function validatePort(mixed $value, string $field): array
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return ['error' => new JsonResponse(['error' => sprintf('%s must be numeric.', $field)], JsonResponse::HTTP_BAD_REQUEST)];
        }
        $port = (int) $value;
        if ($port <= 0 || $port > 65535) {
            return ['error' => new JsonResponse(['error' => sprintf('%s must be between 1 and 65535.', $field)], JsonResponse::HTTP_BAD_REQUEST)];
        }
        return ['port' => $port, 'error' => null];
    }

    private function actionToJobType(string $action): ?string
    {
        return match ($action) {
            'start' => 'ts3.start',
            'stop' => 'ts3.stop',
            'restart' => 'ts3.restart',
            'update' => 'ts3.update',
            'backup' => 'ts3.backup',
            'restore' => 'ts3.restore',
            'token_reset' => 'ts3.token.reset',
            'slots' => 'ts3.slots.set',
            'logs' => 'ts3.logs.export',
            default => null,
        };
    }

    private function buildActionPayload(string $action, array $payload): array
    {
        $extra = [];
        if ($action === 'slots') {
            $slotsValue = $payload['slots'] ?? null;
            if ($slotsValue === null || $slotsValue === '' || !is_numeric($slotsValue)) {
                return ['error' => new JsonResponse(['error' => 'Slots must be numeric.'], JsonResponse::HTTP_BAD_REQUEST)];
            }
            $slots = (int) $slotsValue;
            if ($slots <= 0) {
                return ['error' => new JsonResponse(['error' => 'Slots must be positive.'], JsonResponse::HTTP_BAD_REQUEST)];
            }
            $extra['slots'] = (string) $slots;
        }
        if ($action === 'backup') {
            $backupPath = trim((string) ($payload['backup_path'] ?? ''));
            if ($backupPath !== '') {
                $extra['backup_path'] = $backupPath;
            }
        }
        if ($action === 'restore') {
            $restorePath = trim((string) ($payload['restore_path'] ?? ''));
            if ($restorePath === '') {
                return ['error' => new JsonResponse(['error' => 'restore_path is required.'], JsonResponse::HTTP_BAD_REQUEST)];
            }
            $extra['restore_path'] = $restorePath;
        }

        return ['payload' => $extra, 'error' => null];
    }

    private function queueTs3Job(string $type, Ts3Instance $instance, array $extraPayload): Job
    {
        $payload = array_merge([
            'ts3_instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'service_name' => sprintf('ts3-%d', $instance->getId() ?? 0),
        ], $extraPayload);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        return $job;
    }

    private function normalizeInstance(Ts3Instance $instance): array
    {
        return [
            'id' => $instance->getId(),
            'name' => $instance->getName(),
            'customer_id' => $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'voice_port' => $instance->getVoicePort(),
            'query_port' => $instance->getQueryPort(),
            'file_port' => $instance->getFilePort(),
            'db_mode' => $instance->getDatabaseMode()->value,
            'status' => $instance->getStatus()->value,
            'updated_at' => $instance->getUpdatedAt()->format(DATE_RFC3339),
        ];
    }
}
