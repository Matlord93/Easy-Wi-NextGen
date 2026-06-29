<?php

declare(strict_types=1);

namespace App\Module\Musicbot\UI\Controller\Api;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\Backup\MusicbotBackupOptions;
use App\Module\Musicbot\Application\Backup\MusicbotBackupService;
use App\Module\Musicbot\Application\Backup\MusicbotMigrationService;
use App\Module\Musicbot\Application\Backup\MusicbotRestoreService;
use App\Module\Musicbot\Application\MusicbotPermissionService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotPermission;
use App\Module\Musicbot\Domain\Exception\MusicbotPermissionDeniedException;
use App\Repository\MusicbotInstanceRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/v1/musicbot')]
final class MusicbotBackupApiController
{
    public function __construct(
        private readonly MusicbotInstanceRepository $instanceRepository,
        private readonly MusicbotBackupService $backupService,
        private readonly MusicbotRestoreService $restoreService,
        private readonly MusicbotMigrationService $migrationService,
        private readonly MusicbotPermissionService $permissionService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route(path: '/instances/{instanceId}/backups', name: 'musicbot_backup_list', methods: ['GET'])]
    public function list(Request $request, int $instanceId): JsonResponse
    {
        $actor = $this->requireUser($request);
        $instance = $this->requireInstance($instanceId, $actor);
        if (!$actor->isAdmin()) { $this->assertPerm($actor, $instance, MusicbotPermission::View); }

        return new JsonResponse([
            'backups' => [],
            'instance_id' => $instance->getId(),
            'message' => 'Backup listing requires persistent backup storage configuration.',
        ]);
    }

    #[Route(path: '/instances/{instanceId}/backups', name: 'musicbot_backup_create', methods: ['POST'])]
    public function create(Request $request, int $instanceId): JsonResponse
    {
        $actor = $this->requireUser($request);
        $instance = $this->requireInstance($instanceId, $actor);
        if (!$actor->isAdmin()) { $this->assertPerm($actor, $instance, MusicbotPermission::View); }

        $payload = $this->parseJsonPayload($request);
        $options = MusicbotBackupOptions::fromArray($payload, $actor->isAdmin());

        $manifest = $this->backupService->createBackup($instance, $options);
        $json = $this->backupService->serializeToJson($manifest);

        $this->auditLogger->log($actor, 'musicbot_backup_created', [
            'instance_id' => $instance->getId(),
            'backup_type' => $options->type->value,
        ]);

        return new JsonResponse([
            'success' => true,
            'backup_type' => $manifest->backupType,
            'instance_id' => $manifest->instanceId,
            'customer_id' => $manifest->customerId,
            'created_at' => $manifest->createdAt->format(\DateTimeInterface::ATOM),
            'checksum' => $manifest->checksum,
            'size_bytes' => strlen($json),
        ], Response::HTTP_CREATED);
    }

    #[Route(path: '/instances/{instanceId}/backups/download', name: 'musicbot_backup_download', methods: ['POST'])]
    public function download(Request $request, int $instanceId): Response
    {
        $actor = $this->requireUser($request);
        $instance = $this->requireInstance($instanceId, $actor);
        if (!$actor->isAdmin()) { $this->assertPerm($actor, $instance, MusicbotPermission::View); }

        $payload = $this->parseJsonPayload($request);
        $options = MusicbotBackupOptions::fromArray($payload, $actor->isAdmin());

        $manifest = $this->backupService->createBackup($instance, $options);
        $json = $this->backupService->serializeToJson($manifest);

        $filename = sprintf(
            'musicbot-%d-backup-%s-%s.json',
            $instance->getId(),
            $options->type->value,
            date('Ymd-His'),
        );

        $this->auditLogger->log($actor, 'musicbot_backup_downloaded', [
            'instance_id' => $instance->getId(),
            'backup_type' => $options->type->value,
        ]);

        $response = new StreamedResponse(function () use ($json): void {
            echo $json;
        });

        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        $response->headers->set('Content-Length', (string) strlen($json));

        return $response;
    }

    #[Route(path: '/instances/{instanceId}/backups/restore', name: 'musicbot_backup_restore', methods: ['POST'])]
    public function restore(Request $request, int $instanceId): JsonResponse
    {
        $actor = $this->requireUser($request);
        $instance = $this->requireInstance($instanceId, $actor);
        if (!$actor->isAdmin()) { $this->assertPerm($actor, $instance, MusicbotPermission::Update); }

        $payload = $this->parseJsonPayload($request);

        $backupJson = $payload['backup_json'] ?? null;
        if (!is_string($backupJson) || $backupJson === '') {
            return new JsonResponse(['error' => 'Missing backup_json in request.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $manifest = $this->backupService->deserializeFromJson($backupJson);
            $this->backupService->validateManifest($manifest);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => 'Invalid backup: '.$e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($manifest->customerId !== (string) $instance->getCustomer()->getId() && !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Forbidden: backup belongs to a different customer.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->backupService->verifyChecksum($manifest)) {
            return new JsonResponse(['error' => 'Backup checksum verification failed.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $dryRun = (bool) ($payload['dry_run'] ?? false);
        $report = $this->restoreService->restore($instance, $manifest, $dryRun);

        $this->auditLogger->log($actor, 'musicbot_backup_restored', [
            'instance_id' => $instance->getId(),
            'dry_run' => $dryRun,
            'success' => $report->success,
        ]);

        return new JsonResponse(
            $report->toArray(),
            $report->success ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    private function assertPerm(User $customer, MusicbotInstance $instance, MusicbotPermission $permission): void
    {
        try {
            $this->permissionService->assertActionAllowed($customer, $instance, $permission);
        } catch (MusicbotPermissionDeniedException $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }
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

    private function requireInstance(int $instanceId, User $actor): MusicbotInstance
    {
        $instance = $this->instanceRepository->find($instanceId);

        if ($instance === null) {
            throw new \RuntimeException('Musicbot instance not found.');
        }

        if (!$actor->isAdmin() && $instance->getCustomer()->getId() !== $actor->getId()) {
            throw new \RuntimeException('Forbidden.');
        }

        return $instance;
    }

    /** @return array<string, mixed> */
    private function parseJsonPayload(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\JsonException) {
            return [];
        }
    }
}
