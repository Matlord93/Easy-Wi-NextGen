<?php

declare(strict_types=1);

namespace App\Module\Musicbot\UI\Controller\Admin;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Application\Backup\MusicbotBackupOptions;
use App\Module\Musicbot\Application\Backup\MusicbotBackupService;
use App\Module\Musicbot\Application\Backup\MusicbotBackupType;
use App\Module\Musicbot\Application\Backup\MusicbotMigrationService;
use App\Module\Musicbot\Application\Backup\MusicbotRestoreService;
use App\Repository\MusicbotInstanceRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/v1/admin/musicbot')]
final class AdminMusicbotBackupController
{
    public function __construct(
        private readonly MusicbotInstanceRepository $instanceRepository,
        private readonly MusicbotBackupService $backupService,
        private readonly MusicbotRestoreService $restoreService,
        private readonly MusicbotMigrationService $migrationService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route(path: '/backups/all', name: 'admin_musicbot_backup_list_all', methods: ['GET'])]
    public function listAll(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $instances = $this->instanceRepository->findAll();

        return new JsonResponse([
            'instances' => array_map(fn ($i) => [
                'id' => $i->getId(),
                'name' => $i->getName(),
                'service_name' => $i->getServiceName(),
                'customer_id' => $i->getCustomer()->getId(),
                'node_id' => $i->getNode()->getId(),
            ], $instances),
            'message' => 'Use /api/v1/musicbot/instances/{id}/backups for per-instance backup operations.',
        ]);
    }

    #[Route(path: '/instances/{instanceId}/backups/admin', name: 'admin_musicbot_backup_create', methods: ['POST'])]
    public function createAdminBackup(Request $request, int $instanceId): JsonResponse
    {
        $actor = $this->requireAdmin($request);

        $instance = $this->instanceRepository->find($instanceId);
        if ($instance === null) {
            return new JsonResponse(['error' => 'Instance not found.'], Response::HTTP_NOT_FOUND);
        }

        $payload = $this->parseJsonPayload($request);
        $payload['type'] = MusicbotBackupType::Admin->value;
        $options = MusicbotBackupOptions::fromArray($payload, true);

        $manifest = $this->backupService->createBackup($instance, $options);
        $json = $this->backupService->serializeToJson($manifest);

        $this->auditLogger->log($actor, 'admin_musicbot_backup_created', [
            'instance_id' => $instance->getId(),
            'backup_type' => $options->type->value,
        ]);

        return new JsonResponse([
            'success' => true,
            'backup_type' => $manifest->backupType,
            'instance_id' => $manifest->instanceId,
            'customer_id' => $manifest->customerId,
            'service_name' => $manifest->serviceName,
            'created_at' => $manifest->createdAt->format(\DateTimeInterface::ATOM),
            'checksum' => $manifest->checksum,
            'size_bytes' => strlen($json),
        ], Response::HTTP_CREATED);
    }

    #[Route(path: '/instances/{instanceId}/backups/download', name: 'admin_musicbot_backup_download', methods: ['POST'])]
    public function downloadAdminBackup(Request $request, int $instanceId): Response
    {
        $actor = $this->requireAdmin($request);

        $instance = $this->instanceRepository->find($instanceId);
        if ($instance === null) {
            return new JsonResponse(['error' => 'Instance not found.'], Response::HTTP_NOT_FOUND);
        }

        $payload = $this->parseJsonPayload($request);
        $payload['type'] = MusicbotBackupType::Admin->value;
        $options = MusicbotBackupOptions::fromArray($payload, true);

        $manifest = $this->backupService->createBackup($instance, $options);
        $json = $this->backupService->serializeToJson($manifest);

        $filename = sprintf(
            'musicbot-%d-admin-backup-%s.json',
            $instance->getId(),
            date('Ymd-His'),
        );

        $this->auditLogger->log($actor, 'admin_musicbot_backup_downloaded', [
            'instance_id' => $instance->getId(),
        ]);

        $response = new StreamedResponse(function () use ($json): void {
            echo $json;
        });
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        $response->headers->set('Content-Length', (string) strlen($json));

        return $response;
    }

    #[Route(path: '/instances/{instanceId}/backups/restore', name: 'admin_musicbot_backup_restore', methods: ['POST'])]
    public function restoreOnNode(Request $request, int $instanceId): JsonResponse
    {
        $actor = $this->requireAdmin($request);

        $instance = $this->instanceRepository->find($instanceId);
        if ($instance === null) {
            return new JsonResponse(['error' => 'Instance not found.'], Response::HTTP_NOT_FOUND);
        }

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

        if (!$this->backupService->verifyChecksum($manifest)) {
            return new JsonResponse(['error' => 'Backup checksum verification failed.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $dryRun = (bool) ($payload['dry_run'] ?? false);
        $report = $this->restoreService->restore($instance, $manifest, $dryRun);

        $this->auditLogger->log($actor, 'admin_musicbot_backup_restored', [
            'instance_id' => $instance->getId(),
            'dry_run' => $dryRun,
            'success' => $report->success,
        ]);

        return new JsonResponse([
            'success' => $report->success,
            'dry_run' => $report->dryRun,
            'restored' => $report->restored,
            'warnings' => $report->warnings,
            'missing' => $report->missing,
            'error' => $report->error,
        ], $report->success ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route(path: '/instances/{instanceId}/migration/prepare', name: 'admin_musicbot_migration_prepare', methods: ['POST'])]
    public function prepareMigration(Request $request, int $instanceId): JsonResponse
    {
        $actor = $this->requireAdmin($request);

        $instance = $this->instanceRepository->find($instanceId);
        if ($instance === null) {
            return new JsonResponse(['error' => 'Instance not found.'], Response::HTTP_NOT_FOUND);
        }

        $payload = $this->parseJsonPayload($request);

        $targetNodeId = (string) ($payload['target_node_id'] ?? '');
        if ($targetNodeId === '') {
            return new JsonResponse(['error' => 'target_node_id is required.'], Response::HTTP_BAD_REQUEST);
        }

        $options = MusicbotBackupOptions::fromArray(['type' => MusicbotBackupType::Admin->value], true);
        $manifest = $this->backupService->createBackup($instance, $options);

        $newServiceName = is_string($payload['new_service_name'] ?? null) && $payload['new_service_name'] !== ''
            ? $payload['new_service_name']
            : null;

        $migrationManifest = $this->migrationService->prepareForMigration(
            $manifest,
            $targetNodeId,
            $newServiceName,
        );

        $newInstallBase = (string) ($payload['new_install_base'] ?? '/opt/easywi/musicbot');
        $paths = $this->migrationService->computeNewPaths($instance, $newInstallBase);

        $migrationJson = $this->backupService->serializeToJson($migrationManifest);

        $this->auditLogger->log($actor, 'admin_musicbot_migration_prepared', [
            'instance_id' => $instance->getId(),
            'target_node_id' => $targetNodeId,
        ]);

        return new JsonResponse([
            'success' => true,
            'target_node_id' => $targetNodeId,
            'migration_manifest' => $migrationManifest->toArray(),
            'computed_paths' => $paths,
            'migration_json_size_bytes' => strlen($migrationJson),
            'warnings' => [
                'control_sock will not be transferred',
                'runtime/tmp/logs will not be transferred',
                'PulseAudio/Xvfb/TS3 state will require re-initialization on target node',
            ],
        ]);
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new \RuntimeException('Admin access required.');
        }

        return $actor;
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
