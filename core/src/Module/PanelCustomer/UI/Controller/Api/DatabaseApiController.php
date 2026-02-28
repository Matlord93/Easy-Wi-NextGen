<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Api;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DatabaseNamingPolicy;
use App\Module\Core\Application\DatabaseProvisioningService;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\DatabaseNode;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\JobResult;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\EngineType;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Repository\DatabaseNodeRepository;
use App\Repository\DatabaseRepository;
use App\Repository\JobRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class DatabaseApiController
{
    public function __construct(
        private readonly DatabaseRepository $databaseRepository,
        private readonly DatabaseNodeRepository $databaseNodeRepository,
        private readonly UserRepository $userRepository,
        private readonly JobRepository $jobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly DatabaseProvisioningService $provisioningService,
        private readonly DatabaseNamingPolicy $namingPolicy,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
        private readonly EncryptionService $encryptionService,
    ) {
    }

    #[Route(path: '/api/databases', name: 'databases_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/databases', name: 'databases_list_v1', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);
        $databases = $actor->isAdmin()
            ? $this->databaseRepository->findBy([], ['updatedAt' => 'DESC'], 200)
            : $this->databaseRepository->findByCustomer($actor, 200);

        return new JsonResponse([
            'databases' => array_map(fn (Database $database) => $this->normalizeDatabase($database), $databases),
        ]);
    }

    #[Route(path: '/api/databases', name: 'databases_create', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/databases', name: 'databases_create_v1', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);

        try {
            $payload = $request->toArray();
        } catch (\JsonException) {
            return $this->responseEnvelopeFactory->error($request, 'Invalid JSON payload.', 'invalid_payload', JsonResponse::HTTP_BAD_REQUEST);
        }

        $validated = $this->validateCreatePayload($request, $actor, $payload);
        if ($validated['error'] instanceof JsonResponse) {
            return $validated['error'];
        }

        $customer = $validated['customer'];
        $node = $validated['node'];
        $name = $validated['name'];
        $username = $validated['username'];

        $active = $this->jobRepository->findActiveByTypeAndPayloadField('database.create', 'customer_id', (string) $customer->getId());
        if ($active !== null && (string) ($active->getPayload()['database'] ?? '') === $name) {
            return $this->responseEnvelopeFactory->success(
                $request,
                $active->getId(),
                'Create operation already running.',
                JsonResponse::HTTP_ACCEPTED,
                ['status' => 'running', 'error_code' => 'db_action_in_progress', 'retry_after' => 10],
            );
        }

        $existing = $this->databaseRepository->findOneByCustomerAndName($customer, $node->getEngine(), $name);
        if ($existing instanceof Database) {
            return $this->responseEnvelopeFactory->success($request, 'existing-'.$existing->getId(), 'Database already exists.', JsonResponse::HTTP_OK, [
                'status' => 'succeeded',
                'details' => ['database' => $this->normalizeDatabase($existing)],
            ]);
        }

        $database = new Database(
            $customer,
            strtolower($node->getEngine()),
            $node->getHost(),
            $node->getPort(),
            $name,
            $username,
            null,
            $node,
        );
        $database->setStatus('pending');
        $database->setLastError(null, null);
        $database->setEncryptedPassword(null);
        $this->entityManager->persist($database);
        $this->entityManager->flush();

        $job = $this->provisioningService->buildCreateJobs($database, $node->getAgent()->getId())[0];
        $this->entityManager->persist($job);
        $this->auditLogger->log($actor, 'database.created', [
            'database_id' => $database->getId(),
            'customer_id' => $customer->getId(),
            'name' => $database->getName(),
            'username' => $database->getUsername(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Database creation queued.', JsonResponse::HTTP_ACCEPTED, [
            'details' => ['database' => $this->normalizeDatabase($database)],
        ]);
    }

    #[Route(path: '/api/databases/{id}/password', name: 'databases_password_reset', methods: ['PATCH'])]
    #[Route(path: '/api/v1/customer/databases/{id}/password', name: 'databases_password_reset_v1', methods: ['PATCH'])]
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $database = $this->databaseRepository->find($id);
        if (!$database instanceof Database || !$this->canAccessDatabase($actor, $database)) {
            return $this->responseEnvelopeFactory->error($request, 'Database not found.', 'database_not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->isNodeEligible($database->getNode())) {
            return $this->responseEnvelopeFactory->error($request, 'Database node is disabled.', 'database_node_disabled', JsonResponse::HTTP_CONFLICT);
        }

        $active = $this->findActiveDatabaseActionJob((string) $id);
        if ($active !== null) {
            if ($active->getType() === 'database.rotate_password') {
                return $this->responseEnvelopeFactory->success(
                    $request,
                    $active->getId(),
                    'Password rotation already running.',
                    JsonResponse::HTTP_ACCEPTED,
                    ['status' => 'running', 'error_code' => 'db_action_in_progress', 'retry_after' => 10],
                );
            }

            return $this->responseEnvelopeFactory->error($request, 'Another database action is already running.', 'db_action_in_progress', JsonResponse::HTTP_CONFLICT, 10, ['job_id' => $active->getId()]);
        }

        $database->setStatus('pending');
        $database->setLastError(null, null);

        $job = $this->provisioningService->buildPasswordRotateJob($database, $database->getNode()?->getAgent()->getId() ?? '');
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Password rotation queued.', JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/databases/{id}', name: 'databases_delete', methods: ['DELETE'])]
    #[Route(path: '/api/v1/customer/databases/{id}', name: 'databases_delete_v1', methods: ['DELETE'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $database = $this->databaseRepository->find($id);
        if (!$database instanceof Database || !$this->canAccessDatabase($actor, $database)) {
            return $this->responseEnvelopeFactory->error($request, 'Database not found.', 'database_not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        $active = $this->findActiveDatabaseActionJob((string) $id);
        if ($active !== null) {
            if ($active->getType() === 'database.delete') {
                return $this->responseEnvelopeFactory->success(
                    $request,
                    $active->getId(),
                    'Delete operation already running.',
                    JsonResponse::HTTP_ACCEPTED,
                    ['status' => 'running', 'error_code' => 'db_action_in_progress', 'retry_after' => 10],
                );
            }

            return $this->responseEnvelopeFactory->error($request, 'Another database action is already running.', 'db_action_in_progress', JsonResponse::HTTP_CONFLICT, 10, ['job_id' => $active->getId()]);
        }

        $database->setStatus('deleting');
        $database->setLastError(null, null);

        $job = $this->provisioningService->buildDeleteJob($database, $database->getNode()?->getAgent()->getId() ?? '');
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Database deletion queued.', JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/v1/customer/databases/{id}/jobs/{jobId}/credential', name: 'databases_job_credential_v1', methods: ['GET'])]
    public function consumeCredential(Request $request, int $id, string $jobId): JsonResponse
    {
        $actor = $this->requireUser($request);

        try {
            $credential = $this->entityManager->getConnection()->transactional(function () use ($actor, $id, $jobId): string {
                $database = $this->entityManager->find(Database::class, $id, LockMode::PESSIMISTIC_WRITE);
                if (!$database instanceof Database || !$this->canAccessDatabase($actor, $database)) {
                    throw new \RuntimeException('database_not_found');
                }

                $job = $this->jobRepository->find($jobId);
                if (!$job instanceof Job || !in_array($job->getType(), ['database.create', 'database.rotate_password'], true)) {
                    throw new \RuntimeException('job_not_found');
                }
                if ((string) ($job->getPayload()['database_id'] ?? '') !== (string) $id) {
                    throw new \RuntimeException('job_not_found');
                }

                $result = $job->getResult();
                if (!$result instanceof JobResult || $result->getStatus()->value !== 'succeeded') {
                    throw new \RuntimeException('credential_not_ready');
                }

                $encrypted = $database->getEncryptedPassword();
                if (!is_array($encrypted)) {
                    throw new \RuntimeException('db_credential_already_consumed');
                }

                $credential = trim((string) $this->encryptionService->decrypt($encrypted));
                if ($credential === '') {
                    throw new \RuntimeException('db_credential_already_consumed');
                }

                $database->setEncryptedPassword(null);
                $this->entityManager->flush();

                return $credential;
            });
        } catch (\RuntimeException $exception) {
            return match ($exception->getMessage()) {
                'database_not_found' => $this->responseEnvelopeFactory->error($request, 'Database not found.', 'database_not_found', JsonResponse::HTTP_NOT_FOUND),
                'job_not_found' => $this->responseEnvelopeFactory->error($request, 'Job not found.', 'job_not_found', JsonResponse::HTTP_NOT_FOUND),
                'credential_not_ready' => $this->responseEnvelopeFactory->error($request, 'Credential is not available yet.', 'credential_not_ready', JsonResponse::HTTP_CONFLICT, 5),
                default => $this->responseEnvelopeFactory->error($request, 'Credential already consumed.', 'db_credential_already_consumed', JsonResponse::HTTP_GONE),
            };
        }

        return $this->responseEnvelopeFactory->success($request, $jobId, 'Credential delivered (one-time).', JsonResponse::HTTP_OK, [
            'status' => 'succeeded',
            'details' => ['password' => $credential],
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

    /**
     * @param array<string,mixed> $payload
     * @return array{customer:?User,node:?DatabaseNode,name:string,username:string,error:?JsonResponse}
     */
    private function validateCreatePayload(Request $request, User $actor, array $payload): array
    {
        $nodeId = $payload['node_id'] ?? null;
        $name = trim((string) ($payload['name'] ?? ''));
        $username = trim((string) ($payload['username'] ?? ''));

        if (!is_numeric($nodeId)) {
            return ['customer' => null, 'node' => null, 'name' => '', 'username' => '', 'error' => $this->responseEnvelopeFactory->error($request, 'Node ID must be numeric.', 'database_node_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        $node = $this->databaseNodeRepository->find((int) $nodeId);
        if (!$node instanceof DatabaseNode) {
            return ['customer' => null, 'node' => null, 'name' => '', 'username' => '', 'error' => $this->responseEnvelopeFactory->error($request, 'Database node not found.', 'database_node_not_found', JsonResponse::HTTP_NOT_FOUND)];
        }
        if (!$node->isActive()) {
            return ['customer' => null, 'node' => null, 'name' => '', 'username' => '', 'error' => $this->responseEnvelopeFactory->error($request, 'Database node is disabled.', 'database_node_disabled', JsonResponse::HTTP_CONFLICT)];
        }

        $engine = strtolower($node->getEngine());
        if (!in_array($engine, EngineType::values(), true)) {
            return ['customer' => null, 'node' => null, 'name' => '', 'username' => '', 'error' => $this->responseEnvelopeFactory->error($request, 'Only MySQL/MariaDB/PostgreSQL nodes are allowed.', 'database_engine_unsupported', JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($name === '' || $username === '') {
            return ['customer' => null, 'node' => null, 'name' => '', 'username' => '', 'error' => $this->responseEnvelopeFactory->error($request, 'Missing required fields.', 'validation_failed', JsonResponse::HTTP_BAD_REQUEST)];
        }

        $nameErrors = $this->namingPolicy->validateDatabaseName($name);
        if ($nameErrors !== []) {
            return ['customer' => null, 'node' => null, 'name' => '', 'username' => '', 'error' => $this->responseEnvelopeFactory->error($request, $nameErrors[0], 'db_name_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        $userErrors = $this->namingPolicy->validateUsername($username);
        if ($userErrors !== []) {
            return ['customer' => null, 'node' => null, 'name' => '', 'username' => '', 'error' => $this->responseEnvelopeFactory->error($request, $userErrors[0], 'db_user_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        $customer = $actor;
        if ($actor->isAdmin()) {
            $customer = $this->userRepository->find((int) ($payload['customer_id'] ?? 0));
        }
        if (!$customer instanceof User || $customer->getType() !== UserType::Customer) {
            return ['customer' => null, 'node' => null, 'name' => '', 'username' => '', 'error' => $this->responseEnvelopeFactory->error($request, 'Customer not found.', 'customer_not_found', JsonResponse::HTTP_NOT_FOUND)];
        }

        if ($this->databaseRepository->findOneByCustomerAndName($customer, $engine, $name) instanceof Database) {
            return ['customer' => null, 'node' => null, 'name' => '', 'username' => '', 'error' => $this->responseEnvelopeFactory->error($request, 'Database name already exists.', 'db_name_conflict', JsonResponse::HTTP_CONFLICT)];
        }
        if ($this->databaseRepository->findOneByCustomerAndUsername($customer, $engine, $username) instanceof Database) {
            return ['customer' => null, 'node' => null, 'name' => '', 'username' => '', 'error' => $this->responseEnvelopeFactory->error($request, 'Database username already exists.', 'db_user_conflict', JsonResponse::HTTP_CONFLICT)];
        }

        return ['customer' => $customer, 'node' => $node, 'name' => $name, 'username' => $username, 'error' => null];
    }

    private function isNodeEligible(?DatabaseNode $node): bool
    {
        if (!$node instanceof DatabaseNode || !$node->isActive()) {
            return false;
        }

        return in_array(strtolower($node->getEngine()), EngineType::values(), true);
    }

    private function canAccessDatabase(User $actor, Database $database): bool
    {
        return $actor->isAdmin() || $database->getCustomer()->getId() === $actor->getId();
    }

    private function findActiveDatabaseActionJob(string $databaseId): ?Job
    {
        foreach (['database.rotate_password', 'database.delete'] as $type) {
            $active = $this->jobRepository->findActiveByTypeAndPayloadField($type, 'database_id', $databaseId);
            if ($active !== null) {
                return $active;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeDatabase(Database $database): array
    {
        return [
            'id' => $database->getId(),
            'engine' => $database->getEngine(),
            'host' => $database->getHost(),
            'port' => $database->getPort(),
            'name' => $database->getName(),
            'username' => $database->getUsername(),
            'status' => $database->getStatus(),
            'last_error_code' => $database->getLastErrorCode(),
            'last_error_message' => $database->getLastErrorMessage(),
            'rotated_at' => $database->getRotatedAt()?->format(DATE_RFC3339),
            'customer_id' => $database->getCustomer()->getId(),
            'node' => $database->getNode() === null ? null : [
                'id' => $database->getNode()?->getId(),
                'name' => $database->getNode()?->getName(),
            ],
            'updated_at' => $database->getUpdatedAt()->format(DATE_RFC3339),
        ];
    }
}
