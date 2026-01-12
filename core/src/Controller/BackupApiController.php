<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BackupDefinition;
use App\Entity\BackupSchedule;
use App\Entity\User;
use App\Enum\BackupTargetType;
use App\Enum\UserType;
use App\Repository\BackupDefinitionRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class BackupApiController
{
    public function __construct(
        private readonly BackupDefinitionRepository $backupDefinitionRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route(path: '/api/backups', name: 'backups_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/backups', name: 'backups_list_v1', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);

        $definitions = $actor->getType() === UserType::Admin
            ? $this->backupDefinitionRepository->findBy([], ['updatedAt' => 'DESC'])
            : $this->backupDefinitionRepository->findByCustomer($actor);

        return new JsonResponse([
            'backups' => array_map(fn (BackupDefinition $definition) => $this->normalizeDefinition($definition), $definitions),
        ]);
    }

    #[Route(path: '/api/backups', name: 'backups_create', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/backups', name: 'backups_create_v1', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);
        $payload = $this->parseJsonPayload($request);

        $validation = $this->validateDefinitionPayload($actor, $payload);
        if ($validation['error'] instanceof JsonResponse) {
            return $validation['error'];
        }

        $definition = new BackupDefinition(
            $validation['customer'],
            $validation['target_type'],
            $validation['target_id'],
            $validation['label'],
        );

        $this->entityManager->persist($definition);

        if ($validation['schedule'] !== null) {
            $schedule = $this->createSchedule($definition, $validation['schedule']);
            $definition->setSchedule($schedule);
            $this->entityManager->persist($schedule);
        }

        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'backup.definition.created', [
            'backup_id' => $definition->getId(),
            'customer_id' => $definition->getCustomer()->getId(),
            'target_type' => $definition->getTargetType()->value,
            'target_id' => $definition->getTargetId(),
            'label' => $definition->getLabel(),
        ]);

        if ($definition->getSchedule() !== null) {
            $schedule = $definition->getSchedule();
            $this->auditLogger->log($actor, 'backup.schedule.created', [
                'backup_id' => $definition->getId(),
                'schedule_id' => $schedule->getId(),
                'cron_expression' => $schedule->getCronExpression(),
                'retention_days' => $schedule->getRetentionDays(),
                'retention_count' => $schedule->getRetentionCount(),
                'enabled' => $schedule->isEnabled(),
            ]);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'backup' => $this->normalizeDefinition($definition),
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/backups/{id}/schedule', name: 'backups_schedule_upsert', methods: ['PATCH'])]
    #[Route(path: '/api/v1/customer/backups/{id}/schedule', name: 'backups_schedule_upsert_v1', methods: ['PATCH'])]
    public function upsertSchedule(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $definition = $this->backupDefinitionRepository->find($id);
        if ($definition === null) {
            return new JsonResponse(['error' => 'Backup definition not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessDefinition($actor, $definition)) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $payload = $this->parseJsonPayload($request);
        $scheduleData = $this->validateSchedulePayload($payload);
        if ($scheduleData['error'] instanceof JsonResponse) {
            return $scheduleData['error'];
        }

        $schedule = $definition->getSchedule();
        $action = 'backup.schedule.updated';
        if ($schedule === null) {
            $schedule = $this->createSchedule($definition, $scheduleData);
            $definition->setSchedule($schedule);
            $this->entityManager->persist($schedule);
            $action = 'backup.schedule.created';
        } else {
            $schedule->update(
                $scheduleData['cron_expression'],
                $scheduleData['retention_days'],
                $scheduleData['retention_count'],
                $scheduleData['enabled'],
            );
        }

        $this->auditLogger->log($actor, $action, [
            'backup_id' => $definition->getId(),
            'schedule_id' => $schedule->getId(),
            'cron_expression' => $schedule->getCronExpression(),
            'retention_days' => $schedule->getRetentionDays(),
            'retention_count' => $schedule->getRetentionCount(),
            'enabled' => $schedule->isEnabled(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'backup' => $this->normalizeDefinition($definition),
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

    private function validateDefinitionPayload(User $actor, array $payload): array
    {
        $customerId = $payload['customer_id'] ?? null;
        $targetTypeValue = strtolower(trim((string) ($payload['target_type'] ?? '')));
        $targetId = trim((string) ($payload['target_id'] ?? ''));
        $label = trim((string) ($payload['label'] ?? ''));
        $label = $label === '' ? null : $label;

        if ($actor->getType() === UserType::Admin) {
            if (!is_numeric($customerId)) {
                return ['error' => new JsonResponse(['error' => 'Customer is required.'], JsonResponse::HTTP_BAD_REQUEST)];
            }
        }

        $customer = $actor;
        if ($actor->getType() === UserType::Admin) {
            $customer = $this->userRepository->find((int) $customerId);
            if ($customer === null || $customer->getType() !== UserType::Customer) {
                return ['error' => new JsonResponse(['error' => 'Customer not found.'], JsonResponse::HTTP_NOT_FOUND)];
            }
        }

        if ($targetTypeValue === '') {
            return ['error' => new JsonResponse(['error' => 'Target type is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $targetType = BackupTargetType::tryFrom($targetTypeValue);
        if ($targetType === null) {
            return ['error' => new JsonResponse(['error' => 'Target type is invalid.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($targetId === '') {
            return ['error' => new JsonResponse(['error' => 'Target id is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $schedule = null;
        if ($this->hasSchedulePayload($payload)) {
            $scheduleValidation = $this->validateSchedulePayload($payload);
            if ($scheduleValidation['error'] instanceof JsonResponse) {
                return ['error' => $scheduleValidation['error']];
            }
            $schedule = $scheduleValidation;
        }

        return [
            'error' => null,
            'customer' => $customer,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'label' => $label,
            'schedule' => $schedule,
        ];
    }

    private function hasSchedulePayload(array $payload): bool
    {
        return array_key_exists('cron_expression', $payload)
            || array_key_exists('retention_days', $payload)
            || array_key_exists('retention_count', $payload)
            || array_key_exists('enabled', $payload);
    }

    private function validateSchedulePayload(array $payload): array
    {
        $cronExpression = trim((string) ($payload['cron_expression'] ?? ''));
        $retentionDaysValue = $payload['retention_days'] ?? null;
        $retentionCountValue = $payload['retention_count'] ?? null;
        $enabledValue = $payload['enabled'] ?? true;

        if ($cronExpression === '') {
            return ['error' => new JsonResponse(['error' => 'Cron expression is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($retentionDaysValue === null || $retentionDaysValue === '' || !is_numeric($retentionDaysValue)) {
            return ['error' => new JsonResponse(['error' => 'Retention days must be numeric.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($retentionCountValue === null || $retentionCountValue === '' || !is_numeric($retentionCountValue)) {
            return ['error' => new JsonResponse(['error' => 'Retention count must be numeric.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $retentionDays = (int) $retentionDaysValue;
        $retentionCount = (int) $retentionCountValue;
        if ($retentionDays < 1) {
            return ['error' => new JsonResponse(['error' => 'Retention days must be at least 1.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($retentionCount < 1) {
            return ['error' => new JsonResponse(['error' => 'Retention count must be at least 1.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $enabled = filter_var($enabledValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return ['error' => new JsonResponse(['error' => 'Enabled must be a boolean.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        return [
            'error' => null,
            'cron_expression' => $cronExpression,
            'retention_days' => $retentionDays,
            'retention_count' => $retentionCount,
            'enabled' => $enabled,
        ];
    }

    private function createSchedule(BackupDefinition $definition, array $scheduleData): BackupSchedule
    {
        return new BackupSchedule(
            $definition,
            $scheduleData['cron_expression'],
            $scheduleData['retention_days'],
            $scheduleData['retention_count'],
            $scheduleData['enabled'],
        );
    }

    private function canAccessDefinition(User $actor, BackupDefinition $definition): bool
    {
        return $actor->getType() === UserType::Admin || $definition->getCustomer()->getId() === $actor->getId();
    }

    private function normalizeDefinition(BackupDefinition $definition): array
    {
        $schedule = $definition->getSchedule();

        return [
            'id' => $definition->getId(),
            'customer_id' => $definition->getCustomer()->getId(),
            'target' => [
                'type' => $definition->getTargetType()->value,
                'id' => $definition->getTargetId(),
            ],
            'label' => $definition->getLabel(),
            'schedule' => $schedule === null ? null : [
                'id' => $schedule->getId(),
                'cron_expression' => $schedule->getCronExpression(),
                'retention_days' => $schedule->getRetentionDays(),
                'retention_count' => $schedule->getRetentionCount(),
                'enabled' => $schedule->isEnabled(),
            ],
        ];
    }
}
