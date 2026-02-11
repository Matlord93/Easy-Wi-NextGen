<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Api;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\BackupDefinition;
use App\Module\Core\Domain\Entity\BackupSchedule;
use App\Module\Core\Domain\Entity\BackupTarget;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Repository\BackupDefinitionRepository;
use App\Repository\BackupTargetRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class BackupApiController
{
    public function __construct(
        private readonly BackupDefinitionRepository $backupDefinitionRepository,
        private readonly BackupTargetRepository $backupTargetRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly AppSettingsService $appSettingsService,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
    ) {
    }

    #[Route(path: '/api/backups', name: 'backups_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/backups', name: 'backups_list_v1', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);

        $definitions = $actor->isAdmin()
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

        $validation = $this->validateDefinitionPayload($request, $actor, $payload);
        if ($validation['error'] instanceof JsonResponse) {
            return $validation['error'];
        }

        $definition = new BackupDefinition(
            $validation['customer'],
            $validation['target_type'],
            $validation['target_id'],
            $validation['label'],
            $validation['backup_target'],
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
            'backup_target_id' => $definition->getBackupTarget()?->getId(),
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
                'time_zone' => $schedule->getTimeZone(),
                'compression' => $schedule->getCompression(),
                'stop_before' => $schedule->isStopBefore(),
            ]);
        }

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
            return $this->responseEnvelopeFactory->error($request, 'Backup definition not found.', 'backup_definition_not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessDefinition($actor, $definition)) {
            return $this->responseEnvelopeFactory->error($request, 'Forbidden.', 'forbidden', JsonResponse::HTTP_FORBIDDEN);
        }

        $payload = $this->parseJsonPayload($request);
        $scheduleData = $this->validateSchedulePayload($request, $payload, $definition->getCustomer());
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
                $scheduleData['time_zone'],
                $scheduleData['compression'],
                $scheduleData['stop_before'],
            );
            $schedule->setBackupTarget($scheduleData['backup_target']);
        }

        $this->auditLogger->log($actor, $action, [
            'backup_id' => $definition->getId(),
            'schedule_id' => $schedule->getId(),
            'cron_expression' => $schedule->getCronExpression(),
            'retention_days' => $schedule->getRetentionDays(),
            'retention_count' => $schedule->getRetentionCount(),
            'enabled' => $schedule->isEnabled(),
            'time_zone' => $schedule->getTimeZone(),
            'compression' => $schedule->getCompression(),
            'stop_before' => $schedule->isStopBefore(),
            'backup_target_id' => $schedule->getBackupTarget()?->getId(),
        ]);

        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success(
            $request,
            null,
            'Backup schedule saved.',
            JsonResponse::HTTP_OK,
            [
                'backup' => $this->normalizeDefinition($definition),
            ],
        );
    }

    private function requireUser(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            throw new \RuntimeException('Unauthorized.');
        }

        if (!$actor->isAdmin() && $actor->getType() !== UserType::Customer) {
            throw new \RuntimeException('Forbidden.');
        }

        return $actor;
    }

    private function parseJsonPayload(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\JsonException) {
            return [];
        }
    }

    private function validateDefinitionPayload(Request $request, User $actor, array $payload): array
    {
        $customerIdValue = $payload['customer_id'] ?? null;
        $targetTypeValue = strtolower(trim((string) ($payload['target_type'] ?? '')));
        $targetId = trim((string) ($payload['target_id'] ?? ''));
        $label = trim((string) ($payload['label'] ?? ''));
        $label = $label !== '' ? $label : null;
        $backupTargetId = $payload['backup_target_id'] ?? null;

        $customer = $actor;
        if ($actor->isAdmin()) {
            if ($customerIdValue === null || $customerIdValue === '' || !is_numeric($customerIdValue)) {
                return ['error' => $this->responseEnvelopeFactory->error($request, 'Customer id is required for admin.', 'backup_definition_invalid', JsonResponse::HTTP_BAD_REQUEST)];
            }
            $customer = $this->userRepository->find((int) $customerIdValue);
            if (!$customer instanceof User) {
                return ['error' => $this->responseEnvelopeFactory->error($request, 'Customer not found.', 'backup_definition_invalid', JsonResponse::HTTP_NOT_FOUND)];
            }
        }

        if ($targetTypeValue === '') {
            return ['error' => $this->responseEnvelopeFactory->error($request, 'Target type is required.', 'backup_definition_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        $targetType = BackupTargetType::tryFrom($targetTypeValue);
        if ($targetType === null) {
            return ['error' => $this->responseEnvelopeFactory->error($request, 'Target type is invalid.', 'backup_definition_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($targetId === '') {
            return ['error' => $this->responseEnvelopeFactory->error($request, 'Target id is required.', 'backup_definition_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        $backupTarget = null;
        if ($backupTargetId !== null && $backupTargetId !== '') {
            if (!is_numeric($backupTargetId)) {
                return ['error' => $this->responseEnvelopeFactory->error($request, 'Backup target id must be numeric.', 'backup_target_invalid', JsonResponse::HTTP_BAD_REQUEST)];
            }
            $backupTarget = $this->backupTargetRepository->find((int) $backupTargetId);
            if (!$backupTarget instanceof BackupTarget || $backupTarget->getCustomer()->getId() !== $customer->getId()) {
                return ['error' => $this->responseEnvelopeFactory->error($request, 'Backup target not found.', 'backup_target_invalid', JsonResponse::HTTP_NOT_FOUND)];
            }
        }

        $schedule = null;
        if ($this->hasSchedulePayload($payload)) {
            $scheduleValidation = $this->validateSchedulePayload($request, $payload, $customer);
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
            'backup_target' => $backupTarget,
            'schedule' => $schedule,
        ];
    }

    private function hasSchedulePayload(array $payload): bool
    {
        return array_key_exists('cron_expression', $payload)
            || array_key_exists('retention_days', $payload)
            || array_key_exists('retention_count', $payload)
            || array_key_exists('enabled', $payload)
            || array_key_exists('time_zone', $payload)
            || array_key_exists('compression', $payload)
            || array_key_exists('stop_before', $payload)
            || array_key_exists('backup_target_id', $payload);
    }

    private function validateSchedulePayload(Request $request, array $payload, User $customer): array
    {
        $cronExpression = trim((string) ($payload['cron_expression'] ?? ''));
        $retentionDaysValue = $payload['retention_days'] ?? null;
        $retentionCountValue = $payload['retention_count'] ?? null;
        $enabledValue = $payload['enabled'] ?? true;

        if ($cronExpression === '') {
            return ['error' => $this->responseEnvelopeFactory->error($request, 'Cron expression is required.', 'backup_schedule_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($retentionDaysValue === null || $retentionDaysValue === '' || !is_numeric($retentionDaysValue)) {
            return ['error' => $this->responseEnvelopeFactory->error($request, 'Retention days must be numeric.', 'backup_schedule_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($retentionCountValue === null || $retentionCountValue === '' || !is_numeric($retentionCountValue)) {
            return ['error' => $this->responseEnvelopeFactory->error($request, 'Retention count must be numeric.', 'backup_schedule_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        $retentionDays = (int) $retentionDaysValue;
        $retentionCount = (int) $retentionCountValue;
        if ($retentionDays < 1) {
            return ['error' => $this->responseEnvelopeFactory->error($request, 'Retention days must be at least 1.', 'backup_schedule_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($retentionCount < 1) {
            return ['error' => $this->responseEnvelopeFactory->error($request, 'Retention count must be at least 1.', 'backup_schedule_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        $enabled = filter_var($enabledValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return ['error' => $this->responseEnvelopeFactory->error($request, 'Enabled must be a boolean.', 'backup_schedule_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        $timeZone = trim((string) ($payload['time_zone'] ?? 'UTC'));
        $timeZone = $timeZone === '' ? 'UTC' : $timeZone;
        try {
            new \DateTimeZone($timeZone);
        } catch (\Throwable) {
            return ['error' => $this->responseEnvelopeFactory->error($request, 'Time zone is invalid.', 'backup_schedule_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        $settings = $this->appSettingsService->getSettings();
        $compression = strtolower(trim((string) ($payload['compression'] ?? $settings[AppSettingsService::KEY_BACKUP_DEFAULT_COMPRESSION] ?? 'gzip')));
        if (!in_array($compression, ['gzip', 'zstd'], true)) {
            return ['error' => $this->responseEnvelopeFactory->error($request, 'Compression must be gzip or zstd.', 'backup_schedule_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        $stopBefore = filter_var($payload['stop_before'] ?? ($settings[AppSettingsService::KEY_BACKUP_STOP_BEFORE] ?? false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($stopBefore === null) {
            return ['error' => $this->responseEnvelopeFactory->error($request, 'stop_before must be a boolean.', 'backup_schedule_invalid', JsonResponse::HTTP_BAD_REQUEST)];
        }

        $backupTarget = null;
        $backupTargetIdValue = $payload['backup_target_id'] ?? null;
        if ($backupTargetIdValue !== null && $backupTargetIdValue !== '' && $backupTargetIdValue !== 'default') {
            if (!is_numeric($backupTargetIdValue)) {
                return ['error' => $this->responseEnvelopeFactory->error($request, 'Backup target id must be numeric.', 'backup_target_invalid', JsonResponse::HTTP_BAD_REQUEST)];
            }

            $backupTarget = $this->backupTargetRepository->find((int) $backupTargetIdValue);
            if (!$backupTarget instanceof BackupTarget || $backupTarget->getCustomer()->getId() !== $customer->getId() || !$backupTarget->isEnabled()) {
                return ['error' => $this->responseEnvelopeFactory->error($request, 'Backup target is invalid or disabled.', 'backup_target_invalid', JsonResponse::HTTP_CONFLICT)];
            }
        }

        return [
            'error' => null,
            'cron_expression' => $cronExpression,
            'retention_days' => $retentionDays,
            'retention_count' => $retentionCount,
            'enabled' => $enabled,
            'time_zone' => $timeZone,
            'compression' => $compression,
            'stop_before' => (bool) $stopBefore,
            'backup_target' => $backupTarget,
        ];
    }

    private function createSchedule(BackupDefinition $definition, array $scheduleData): BackupSchedule
    {
        $schedule = new BackupSchedule(
            $definition,
            $scheduleData['cron_expression'],
            $scheduleData['retention_days'],
            $scheduleData['retention_count'],
            $scheduleData['enabled'],
        );
        $schedule->setTimeZone($scheduleData['time_zone'] ?? 'UTC');
        $schedule->setCompression($scheduleData['compression'] ?? 'gzip');
        $schedule->setStopBefore((bool) ($scheduleData['stop_before'] ?? false));
        $schedule->setBackupTarget($scheduleData['backup_target'] ?? null);

        return $schedule;
    }

    private function canAccessDefinition(User $actor, BackupDefinition $definition): bool
    {
        return $actor->isAdmin() || $definition->getCustomer()->getId() === $actor->getId();
    }

    private function normalizeDefinition(BackupDefinition $definition): array
    {
        $schedule = $definition->getSchedule();
        $backupTarget = $definition->getBackupTarget();

        return [
            'id' => $definition->getId(),
            'customer_id' => $definition->getCustomer()->getId(),
            'target' => [
                'type' => $definition->getTargetType()->value,
                'id' => $definition->getTargetId(),
            ],
            'label' => $definition->getLabel(),
            'backup_target' => $backupTarget === null ? null : [
                'id' => $backupTarget->getId(),
                'type' => $backupTarget->getType()->value,
                'label' => $backupTarget->getLabel(),
            ],
            'schedule' => $schedule === null ? null : [
                'id' => $schedule->getId(),
                'cron_expression' => $schedule->getCronExpression(),
                'retention_days' => $schedule->getRetentionDays(),
                'retention_count' => $schedule->getRetentionCount(),
                'enabled' => $schedule->isEnabled(),
                'time_zone' => $schedule->getTimeZone(),
                'compression' => $schedule->getCompression(),
                'stop_before' => $schedule->isStopBefore(),
                'backup_target_id' => $schedule->getBackupTarget()?->getId(),
                'last_run_at' => $schedule->getLastRunAt()?->format(DATE_ATOM),
                'last_status' => $schedule->getLastStatus(),
                'last_error_code' => $schedule->getLastErrorCode(),
            ],
        ];
    }
}
