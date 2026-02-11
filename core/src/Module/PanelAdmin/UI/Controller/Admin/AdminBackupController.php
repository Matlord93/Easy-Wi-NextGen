<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Message\InstanceActionMessage;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Backup;
use App\Module\Core\Domain\Entity\BackupDefinition;
use App\Module\Core\Domain\Entity\BackupTarget;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\BackupDestinationType;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Repository\BackupDefinitionRepository;
use App\Repository\BackupRepository;
use App\Repository\BackupTargetRepository;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/backups')]
final class AdminBackupController
{
    public function __construct(
        private readonly BackupRepository $backupRepository,
        private readonly BackupTargetRepository $backupTargetRepository,
        private readonly BackupDefinitionRepository $backupDefinitionRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly JobRepository $jobRepository,
        private readonly AppSettingsService $settingsService,
        private readonly EncryptionService $encryptionService,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_backups_overview', methods: ['GET'])]
    public function overview(Request $request): Response
    {
        $this->requireAdmin($request);

        $backups = $this->backupRepository->findBy([], ['createdAt' => 'DESC'], 100);
        $targets = $this->backupTargetRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/backups/index.html.twig', [
            'activeNav' => 'instances',
            'backups' => array_map(fn (Backup $backup): array => [
                'id' => $backup->getId(),
                'instance_id' => $backup->getDefinition()->getTargetId(),
                'customer_id' => $backup->getDefinition()->getCustomer()->getId(),
                'target' => $backup->getDefinition()->getBackupTarget()?->getLabel(),
                'status' => $backup->getStatus()->value,
                'size_bytes' => $backup->getSizeBytes(),
                'checksum' => $backup->getChecksumSha256(),
                'created_at' => $backup->getCreatedAt(),
                'completed_at' => $backup->getCompletedAt(),
                'error' => $backup->getErrorMessage(),
                'job_id' => $backup->getJob()?->getId(),
            ], $backups),
            'targets' => array_map(fn (BackupTarget $target): array => $this->normalizeTarget($target), $targets),
        ]));
    }

    #[Route(path: '/targets', name: 'admin_backups_targets', methods: ['GET'])]
    public function targets(Request $request): Response
    {
        $this->requireAdmin($request);

        $targets = $this->backupTargetRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/backups/targets.html.twig', [
            'activeNav' => 'instances',
            'targets' => array_map(fn (BackupTarget $target): array => $this->normalizeTarget($target), $targets),
        ]));
    }

    #[Route(path: '/settings', name: 'admin_settings_backups', methods: ['GET'])]
    #[Route(path: '/admin/settings/backups', name: 'admin_settings_backups_alias', methods: ['GET'])]
    public function settings(Request $request): Response
    {
        $this->requireAdmin($request);
        $settings = $this->settingsService->getSettings();

        return new Response($this->twig->render('admin/backups/settings.html.twig', [
            'activeNav' => 'backup-system',
            'settings' => $settings,
            'targets' => array_map(fn (BackupTarget $target): array => $this->normalizeTarget($target), $this->backupTargetRepository->findBy([], ['label' => 'ASC'])),
        ]));
    }

    #[Route(path: '/api/targets', name: 'admin_backups_targets_upsert', methods: ['POST'])]
    public function upsertTarget(Request $request): JsonResponse
    {
        $admin = $this->requireAdmin($request);
        $payload = $this->parsePayload($request);

        $targetId = $payload['target_id'] ?? null;
        $target = null;
        if (is_numeric($targetId)) {
            $target = $this->backupTargetRepository->find((int) $targetId);
            if (!$target instanceof BackupTarget) {
                return $this->responseEnvelopeFactory->error($request, 'Target not found.', 'backup_target_invalid', JsonResponse::HTTP_NOT_FOUND);
            }
        }

        $type = BackupDestinationType::tryFrom(strtolower(trim((string) ($payload['type'] ?? ($target?->getType()->value ?? '')))));
        if ($type === null || !in_array($type, [BackupDestinationType::Local, BackupDestinationType::Webdav, BackupDestinationType::Nextcloud], true)) {
            return $this->responseEnvelopeFactory->error($request, 'Target type is invalid.', 'backup_target_invalid', JsonResponse::HTTP_BAD_REQUEST);
        }

        $label = trim((string) ($payload['name'] ?? $payload['label'] ?? $target?->getLabel() ?? ''));
        if ($label === '') {
            return $this->responseEnvelopeFactory->error($request, 'Target name is required.', 'backup_target_invalid', JsonResponse::HTTP_BAD_REQUEST);
        }

        $enabled = (bool) ($payload['enabled'] ?? true);
        $verifyTls = (bool) ($payload['verify_tls'] ?? true);
        $config = $target?->getConfig() ?? [];

        if ($type === BackupDestinationType::Local) {
            $basePath = trim((string) ($payload['base_path'] ?? ($config['base_path'] ?? '')));
            if ($basePath === '' || !str_starts_with($basePath, '/')) {
                return $this->responseEnvelopeFactory->error($request, 'Local base path must be absolute.', 'backup_target_invalid', JsonResponse::HTTP_BAD_REQUEST);
            }
            $config = ['base_path' => $basePath];
        } else {
            $url = trim((string) ($payload['url'] ?? ($config['url'] ?? '')));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                return $this->responseEnvelopeFactory->error($request, 'WebDAV URL must start with http/https.', 'backup_target_invalid', JsonResponse::HTTP_BAD_REQUEST);
            }
            $remotePath = '/' . ltrim(trim((string) ($payload['remote_path'] ?? ($config['remote_path'] ?? '/'))), '/');
            $username = trim((string) ($payload['username'] ?? ($config['username'] ?? '')));
            if ($username === '') {
                return $this->responseEnvelopeFactory->error($request, 'WebDAV username is required.', 'backup_target_invalid', JsonResponse::HTTP_BAD_REQUEST);
            }
            $config = [
                'url' => rtrim($url, '/'),
                'remote_path' => $remotePath,
                'username' => $username,
                'verify_tls' => $verifyTls,
            ];
        }

        $isNew = !$target instanceof BackupTarget;
        if ($isNew) {
            $target = new BackupTarget($admin, $type, $label, $config, null, $enabled);
        } else {
            $target->setType($type);
            $target->setLabel($label);
            $target->setConfig($config);
            $target->setEnabled($enabled);
        }

        $newSecret = trim((string) ($payload['password'] ?? $payload['token'] ?? ''));
        if (in_array($type, [BackupDestinationType::Webdav, BackupDestinationType::Nextcloud], true) && $newSecret !== '') {
            $target->setEncryptedCredentials([
                'password' => $this->encryptionService->encrypt($newSecret),
            ]);
        }

        $this->entityManager->persist($target);
        $this->entityManager->flush();

        $this->auditLogger->log($admin, $isNew ? 'admin.backup.target.created' : 'admin.backup.target.updated', [
            'target_id' => $target->getId(),
            'type' => $target->getType()->value,
            'enabled' => $target->isEnabled(),
            'url_host' => $this->sanitizeUrlHost((string) ($target->getConfig()['url'] ?? '')),
            'remote_path' => (string) ($target->getConfig()['remote_path'] ?? ''),
            'request_id' => $this->resolveRequestId($request),
        ]);

        return $this->responseEnvelopeFactory->success(
            $request,
            sprintf('backup-target-%d', (int) $target->getId()),
            'Backup target saved.',
            JsonResponse::HTTP_OK,
            ['target' => $this->normalizeTarget($target)],
        );
    }

    #[Route(path: '/targets/{id}/test', name: 'admin_backups_target_test', methods: ['POST'])]
    public function testTarget(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireAdmin($request);
        $target = $this->backupTargetRepository->find($id);
        if (!$target instanceof BackupTarget) {
            return $this->responseEnvelopeFactory->error($request, 'Target not found.', 'backup_target_invalid', JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$target->isEnabled()) {
            return $this->responseEnvelopeFactory->error($request, 'Target is disabled.', 'backup_target_invalid', JsonResponse::HTTP_CONFLICT);
        }

        $config = $target->getConfig();
        $resultDetails = [
            'target_id' => $target->getId(),
            'type' => $target->getType()->value,
            'url_host' => $this->sanitizeUrlHost((string) ($config['url'] ?? '')),
            'remote_path' => (string) ($config['remote_path'] ?? ''),
        ];

        if ($target->getType() === BackupDestinationType::Local) {
            $basePath = trim((string) ($config['base_path'] ?? ''));
            if ($basePath === '' || !str_starts_with($basePath, '/')) {
                return $this->responseEnvelopeFactory->error($request, 'Local base path must be absolute.', 'backup_target_invalid', JsonResponse::HTTP_BAD_REQUEST, null, ['details' => $resultDetails]);
            }
            if (!is_dir($basePath) && !@mkdir($basePath, 0770, true) && !is_dir($basePath)) {
                return $this->responseEnvelopeFactory->error($request, 'Cannot create local backup directory.', 'backup_target_connection_failed', JsonResponse::HTTP_CONFLICT, null, ['details' => $resultDetails]);
            }

            $tmp = rtrim($basePath, '/') . '/.easywi-target-test-' . bin2hex(random_bytes(4));
            $written = @file_put_contents($tmp, 'ok');
            if ($written === false) {
                return $this->responseEnvelopeFactory->error($request, 'Local target is not writable.', 'backup_target_connection_failed', JsonResponse::HTTP_CONFLICT, null, ['details' => $resultDetails]);
            }
            @unlink($tmp);

            $resultDetails['disk_free_bytes'] = @disk_free_space($basePath) ?: null;
            $resultDetails['status'] = 'ok';

            $this->auditLogger->log($admin, 'admin.backup.target.tested', ['target_id' => $target->getId(), 'result' => 'ok', 'request_id' => $this->resolveRequestId($request)]);

            return $this->responseEnvelopeFactory->success($request, sprintf('target-test-%d', $target->getId()), 'Local target test succeeded.', JsonResponse::HTTP_OK, ['details' => $resultDetails]);
        }

        if (!in_array($target->getType(), [BackupDestinationType::Webdav, BackupDestinationType::Nextcloud], true)) {
            return $this->responseEnvelopeFactory->error($request, 'Unsupported target type.', 'backup_target_invalid', JsonResponse::HTTP_BAD_REQUEST, null, ['details' => $resultDetails]);
        }

        $encrypted = $target->getEncryptedCredentials();
        $passwordEncrypted = is_string($encrypted['password'] ?? null) ? (string) $encrypted['password'] : '';
        if ($passwordEncrypted === '') {
            return $this->responseEnvelopeFactory->error($request, 'WebDAV secret missing.', 'backup_target_secret_missing', JsonResponse::HTTP_BAD_REQUEST, null, ['details' => $resultDetails]);
        }

        $url = (string) ($config['url'] ?? '');
        $username = (string) ($config['username'] ?? '');
        $remotePath = '/' . ltrim((string) ($config['remote_path'] ?? '/'), '/');
        $verifyTls = (bool) ($config['verify_tls'] ?? true);

        $attempts = 2;
        $timeoutSeconds = 8;
        $statusLine = '';
        $bodyPreview = null;
        $ok = false;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            [$statusLine, $bodyPreview] = $this->performWebdavPropfind(
                rtrim($url, '/') . $remotePath,
                $username,
                $this->encryptionService->decrypt($passwordEncrypted),
                $verifyTls,
                $timeoutSeconds,
            );
            if (str_contains($statusLine, ' 200 ') || str_contains($statusLine, ' 207 ')) {
                $ok = true;
                break;
            }
        }

        $resultDetails['http_status'] = $statusLine;
        $resultDetails['attempts'] = $attempts;
        $resultDetails['timeout_seconds'] = $timeoutSeconds;
        $resultDetails['body_preview'] = $bodyPreview;

        $this->auditLogger->log($admin, 'admin.backup.target.tested', [
            'target_id' => $target->getId(),
            'result' => $ok ? 'ok' : 'failed',
            'http_status' => $statusLine,
            'url_host' => $resultDetails['url_host'],
            'remote_path' => $resultDetails['remote_path'],
            'request_id' => $this->resolveRequestId($request),
        ]);

        if (!$ok) {
            return $this->responseEnvelopeFactory->error($request, 'WebDAV target connection failed.', 'backup_target_connection_failed', JsonResponse::HTTP_CONFLICT, 5, ['details' => $resultDetails]);
        }

        return $this->responseEnvelopeFactory->success($request, sprintf('target-test-%d', $target->getId()), 'WebDAV target test succeeded.', JsonResponse::HTTP_OK, ['details' => $resultDetails]);
    }

    #[Route(path: '/api/settings', name: 'admin_settings_backups_update', methods: ['POST'])]
    public function updateSettings(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $payload = $this->parsePayload($request);

        $this->settingsService->updateSettings([
            AppSettingsService::KEY_BACKUP_DEFAULT_TARGET_ID => is_numeric($payload['default_target_id'] ?? null) ? (int) $payload['default_target_id'] : null,
            AppSettingsService::KEY_BACKUP_DEFAULT_RETENTION_COUNT => max(0, (int) ($payload['retention_count'] ?? 7)),
            AppSettingsService::KEY_BACKUP_DEFAULT_RETENTION_AGE_DAYS => max(0, (int) ($payload['retention_age_days'] ?? 30)),
            AppSettingsService::KEY_BACKUP_DEFAULT_COMPRESSION => in_array(($payload['compression'] ?? 'gzip'), ['gzip', 'zstd'], true) ? (string) $payload['compression'] : 'gzip',
            AppSettingsService::KEY_BACKUP_STOP_BEFORE => (bool) ($payload['stop_before_backup'] ?? false),
            AppSettingsService::KEY_BACKUP_MAX_SIZE_BYTES => is_numeric($payload['max_backup_size_bytes'] ?? null) ? (int) $payload['max_backup_size_bytes'] : null,
            AppSettingsService::KEY_BACKUP_WEBDAV_VERIFY_TLS => (bool) ($payload['webdav_verify_tls'] ?? true),
        ]);

        $this->auditLogger->log($this->requireAdmin($request), 'admin.backup.settings.updated', ['request_id' => $this->resolveRequestId($request)]);

        return $this->responseEnvelopeFactory->success($request, 'settings-updated', 'Backup settings updated.', JsonResponse::HTTP_OK);
    }

    #[Route(path: '/api/instances/{id}/backup-create', name: 'admin_instance_backup_create', methods: ['POST'])]
    public function createBackupForInstance(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireAdmin($request);
        $instance = $this->instanceRepository->find($id);
        if (!$instance instanceof Instance) {
            throw new NotFoundHttpException('Instance not found.');
        }

        $payload = $this->parsePayload($request);
        $target = null;
        if (is_numeric($payload['target_id'] ?? null)) {
            $target = $this->backupTargetRepository->find((int) $payload['target_id']);
        }

        $active = $this->jobRepository->findLatestActiveByTypesAndInstanceId(['instance.backup.create', 'instance.backup.restore'], $instance->getId() ?? 0);
        if ($active !== null) {
            if ($active->getType() === 'instance.backup.create') {
                return $this->responseEnvelopeFactory->success($request, $active->getId(), 'Backup create already queued.', JsonResponse::HTTP_ACCEPTED, ['retry_after' => 10]);
            }

            return $this->responseEnvelopeFactory->error($request, 'Another backup action is already running.', 'backup_action_in_progress', JsonResponse::HTTP_CONFLICT, 10);
        }

        $block = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($block !== null) {
            return $this->responseEnvelopeFactory->error($request, $block, 'disk_quota_exceeded', JsonResponse::HTTP_CONFLICT);
        }

        $definition = $this->resolveDefinition($instance, $target);
        $message = new InstanceActionMessage('instance.backup.create', $admin->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'definition_id' => $definition->getId(),
            'install_path' => $instance->getInstallPath(),
            'request_id' => $this->resolveRequestId($request),
        ]);

        $jobId = $this->dispatchJob($message)['job_id'] ?? '';

        return $this->responseEnvelopeFactory->success($request, (string) $jobId, 'Backup queued.', JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/instances/{id}/backup-restore', name: 'admin_instance_backup_restore', methods: ['POST'])]
    public function restoreBackupForInstance(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireAdmin($request);
        $instance = $this->instanceRepository->find($id);
        if (!$instance instanceof Instance) {
            throw new NotFoundHttpException('Instance not found.');
        }

        $payload = $this->parsePayload($request);
        if (!(bool) ($payload['confirm'] ?? false)) {
            return $this->responseEnvelopeFactory->error($request, 'Restore confirmation required.', 'backup_restore_requires_confirm', JsonResponse::HTTP_BAD_REQUEST);
        }

        $backupId = $payload['backup_id'] ?? null;
        if (!is_numeric($backupId)) {
            return $this->responseEnvelopeFactory->error($request, 'backup_id is required.', 'backup_target_invalid', JsonResponse::HTTP_BAD_REQUEST);
        }

        $active = $this->jobRepository->findLatestActiveByTypesAndInstanceId(['instance.backup.create', 'instance.backup.restore'], $instance->getId() ?? 0);
        if ($active !== null) {
            if ($active->getType() === 'instance.backup.restore') {
                return $this->responseEnvelopeFactory->success($request, $active->getId(), 'Backup restore already queued.', JsonResponse::HTTP_ACCEPTED, ['retry_after' => 10]);
            }

            return $this->responseEnvelopeFactory->error($request, 'Another backup action is already running.', 'backup_action_in_progress', JsonResponse::HTTP_CONFLICT, 10);
        }

        $message = new InstanceActionMessage('instance.backup.restore', $admin->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'backup_id' => (int) $backupId,
            'confirm' => 'true',
            'pre_backup' => (bool) ($payload['pre_backup'] ?? false) ? 'true' : 'false',
            'install_path' => $instance->getInstallPath(),
            'request_id' => $this->resolveRequestId($request),
        ]);

        $jobId = $this->dispatchJob($message)['job_id'] ?? '';

        return $this->responseEnvelopeFactory->success($request, (string) $jobId, 'Restore queued.', JsonResponse::HTTP_ACCEPTED);
    }

    private function resolveDefinition(Instance $instance, ?BackupTarget $target): BackupDefinition
    {
        foreach ($this->backupDefinitionRepository->findByCustomer($instance->getCustomer()) as $definition) {
            if ($definition->getTargetType() === BackupTargetType::Game
                && $definition->getTargetId() === (string) $instance->getId()
                && $definition->getBackupTarget()?->getId() === $target?->getId()) {
                return $definition;
            }
        }

        $definition = new BackupDefinition($instance->getCustomer(), BackupTargetType::Game, (string) $instance->getId(), 'Admin backup', $target);
        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        return $definition;
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }
        if (!$actor->isAdmin()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $actor;
    }

    private function parsePayload(Request $request): array
    {
        try {
            $payload = $request->toArray();
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Invalid JSON payload.', $exception);
        }

        return is_array($payload) ? $payload : [];
    }

    /** @return array<string,mixed> */
    private function dispatchJob(InstanceActionMessage $message): array
    {
        $envelope = $this->messageBus->dispatch($message);
        $handled = $envelope->last(HandledStamp::class);
        $result = $handled?->getResult();
        return is_array($result) ? $result : [];
    }

    /** @return array<string,mixed> */
    private function normalizeTarget(BackupTarget $target): array
    {
        return [
            'id' => $target->getId(),
            'name' => $target->getLabel(),
            'type' => $target->getType()->value,
            'enabled' => $target->isEnabled(),
            'config' => $target->getConfig(),
            'secret_set' => $target->hasEncryptedCredential('password'),
        ];
    }

    /** @return array{0:string,1:?string} */
    private function performWebdavPropfind(string $url, string $username, string $password, bool $verifyTls, int $timeoutSeconds): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'PROPFIND',
                'header' => "Depth: 0\r\nAuthorization: Basic " . base64_encode($username . ':' . $password),
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $verifyTls,
                'verify_peer_name' => $verifyTls,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        $headers = is_array($http_response_header) ? $http_response_header : [];
        $statusLine = is_string($headers[0] ?? null) ? (string) $headers[0] : '';

        return [$statusLine, is_string($result) ? substr($result, 0, 128) : null];
    }

    private function sanitizeUrlHost(string $url): string
    {
        $parts = @parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return '';
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $host . $port;
    }

    private function resolveRequestId(Request $request): string
    {
        $header = trim((string) ($request->headers->get('X-Request-ID') ?? ''));
        if ($header !== '') {
            return $header;
        }

        return trim((string) ($request->attributes->get('request_id') ?? ''));
    }
}
