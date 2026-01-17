<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Api;

use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\DatabaseRepository;
use App\Repository\UserRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class DatabaseApiController
{
    public function __construct(
        private readonly DatabaseRepository $databaseRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly EncryptionService $encryptionService,
    ) {
    }

    #[Route(path: '/api/databases', name: 'databases_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/databases', name: 'databases_list_v1', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);

        $databases = $actor->isAdmin()
            ? $this->databaseRepository->findBy([], ['updatedAt' => 'DESC'])
            : $this->databaseRepository->findByCustomer($actor);

        return new JsonResponse([
            'databases' => array_map(fn (Database $database) => $this->normalizeDatabase($database), $databases),
        ]);
    }

    #[Route(path: '/api/databases', name: 'databases_create', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/databases', name: 'databases_create_v1', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);
        $payload = $this->parseJsonPayload($request);

        $formData = $this->validatePayload($actor, $payload, true);
        if ($formData['error'] instanceof JsonResponse) {
            return $formData['error'];
        }

        $database = new Database(
            $formData['customer'],
            $formData['engine'],
            $formData['host'],
            $formData['port'],
            $formData['name'],
            $formData['username'],
            $formData['encrypted_password'],
        );

        $this->entityManager->persist($database);
        $this->entityManager->flush();

        $job = $this->queueDatabaseJob('database.create', $database, [
            'engine' => $database->getEngine(),
            'host' => $database->getHost(),
            'port' => (string) $database->getPort(),
            'database' => $database->getName(),
            'username' => $database->getUsername(),
            'encrypted_password' => $database->getEncryptedPassword(),
        ]);

        $this->auditLogger->log($actor, 'database.created', [
            'database_id' => $database->getId(),
            'customer_id' => $database->getCustomer()->getId(),
            'engine' => $database->getEngine(),
            'host' => $database->getHost(),
            'port' => $database->getPort(),
            'name' => $database->getName(),
            'username' => $database->getUsername(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'database' => $this->normalizeDatabase($database),
            'job_id' => $job->getId(),
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/databases/{id}/password', name: 'databases_password_reset', methods: ['PATCH'])]
    #[Route(path: '/api/v1/customer/databases/{id}/password', name: 'databases_password_reset_v1', methods: ['PATCH'])]
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $database = $this->databaseRepository->find($id);
        if ($database === null) {
            return new JsonResponse(['error' => 'Database not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessDatabase($actor, $database)) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $payload = $this->parseJsonPayload($request);
        $password = trim((string) ($payload['password'] ?? ''));
        if ($password === '') {
            return new JsonResponse(['error' => 'Password is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($password) < 8) {
            return new JsonResponse(['error' => 'Password must be at least 8 characters.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $encryptedPassword = $this->encryptionService->encrypt($password);
        $database->setEncryptedPassword($encryptedPassword);

        $job = $this->queueDatabaseJob('database.password.reset', $database, [
            'username' => $database->getUsername(),
            'encrypted_password' => $database->getEncryptedPassword(),
        ]);

        $this->auditLogger->log($actor, 'database.password_reset', [
            'database_id' => $database->getId(),
            'name' => $database->getName(),
            'username' => $database->getUsername(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'database' => $this->normalizeDatabase($database),
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

    private function parseJsonPayload(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\JsonException $exception) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid JSON payload.', $exception);
        }
    }

    private function validatePayload(User $actor, array $payload, bool $requirePassword): array
    {
        $customerId = $payload['customer_id'] ?? null;
        $engine = strtolower(trim((string) ($payload['engine'] ?? '')));
        $host = trim((string) ($payload['host'] ?? ''));
        $portValue = $payload['port'] ?? null;
        $name = trim((string) ($payload['name'] ?? ''));
        $username = trim((string) ($payload['username'] ?? ''));
        $password = trim((string) ($payload['password'] ?? ''));

        if ($actor->isAdmin()) {
            if (!is_numeric($customerId)) {
                return ['error' => new JsonResponse(['error' => 'Customer is required.'], JsonResponse::HTTP_BAD_REQUEST)];
            }
        }

        if ($engine === '') {
            return ['error' => new JsonResponse(['error' => 'Engine is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($host === '') {
            return ['error' => new JsonResponse(['error' => 'Host is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($portValue === null || $portValue === '' || !is_numeric($portValue)) {
            return ['error' => new JsonResponse(['error' => 'Port must be numeric.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $port = (int) $portValue;
        if ($port <= 0 || $port > 65535) {
            return ['error' => new JsonResponse(['error' => 'Port must be between 1 and 65535.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($name === '') {
            return ['error' => new JsonResponse(['error' => 'Database name is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($username === '') {
            return ['error' => new JsonResponse(['error' => 'Username is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($requirePassword && $password === '') {
            return ['error' => new JsonResponse(['error' => 'Password is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($password !== '' && mb_strlen($password) < 8) {
            return ['error' => new JsonResponse(['error' => 'Password must be at least 8 characters.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $customer = $actor;
        if ($actor->isAdmin()) {
            $customer = $this->userRepository->find((int) $customerId);
            if ($customer === null || $customer->getType() !== UserType::Customer) {
                return ['error' => new JsonResponse(['error' => 'Customer not found.'], JsonResponse::HTTP_NOT_FOUND)];
            }
        }

        if ($customer->getType() !== UserType::Customer) {
            return ['error' => new JsonResponse(['error' => 'Customer not found.'], JsonResponse::HTTP_NOT_FOUND)];
        }

        return [
            'customer' => $customer,
            'engine' => $engine,
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'username' => $username,
            'encrypted_password' => $password !== '' ? $this->encryptionService->encrypt($password) : null,
            'error' => null,
        ];
    }

    private function canAccessDatabase(User $actor, Database $database): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        return $database->getCustomer()->getId() === $actor->getId();
    }

    private function queueDatabaseJob(string $type, Database $database, array $extraPayload): Job
    {
        $payload = array_merge([
            'database_id' => (string) ($database->getId() ?? ''),
            'customer_id' => (string) $database->getCustomer()->getId(),
        ], $extraPayload);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        return $job;
    }

    private function normalizeDatabase(Database $database): array
    {
        return [
            'id' => $database->getId(),
            'engine' => $database->getEngine(),
            'host' => $database->getHost(),
            'port' => $database->getPort(),
            'name' => $database->getName(),
            'username' => $database->getUsername(),
            'customer_id' => $database->getCustomer()->getId(),
            'updated_at' => $database->getUpdatedAt()->format(DATE_RFC3339),
        ];
    }
}
