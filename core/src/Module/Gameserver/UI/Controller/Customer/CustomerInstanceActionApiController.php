<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Message\InstanceActionMessage;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Application\SetupChecker;
use App\Module\Core\Domain\Entity\BackupDefinition;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\BackupStatus;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\Domain\Enum\InstanceScheduleAction;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Gameserver\Application\ConsoleCommandValidator;
use App\Module\Gameserver\Application\Console\ConsoleStreamDiagnostics;
use App\Module\Gameserver\Application\GameServerPathResolver;
use App\Module\Gameserver\Application\InstanceJobPayloadBuilder;
use App\Module\Gameserver\Application\MinecraftCatalogService;
use App\Module\Gameserver\Application\TemplateInstallResolver;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Repository\BackupDefinitionRepository;
use App\Repository\BackupRepository;
use App\Repository\GamePluginRepository;
use App\Repository\InstanceRepository;
use App\Repository\JobLogRepository;
use App\Repository\JobRepository;
use Cron\CronExpression;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use App\Module\Core\Attribute\RequiresModule;

#[RequiresModule('game')]
final class CustomerInstanceActionApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly BackupDefinitionRepository $backupDefinitionRepository,
        private readonly BackupRepository $backupRepository,
        private readonly GamePluginRepository $gamePluginRepository,
        private readonly PortBlockRepository $portBlockRepository,
        private readonly JobRepository $jobRepository,
        private readonly JobLogRepository $jobLogRepository,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly AuditLogger $auditLogger,
        private readonly ConsoleCommandValidator $consoleCommandValidator,
        private readonly GameServerPathResolver $gameServerPathResolver,
        private readonly SetupChecker $setupChecker,
        private readonly AppSettingsService $appSettingsService,
        private readonly MinecraftCatalogService $minecraftCatalogService,
        private readonly TemplateInstallResolver $templateInstallResolver,
        private readonly InstanceJobPayloadBuilder $instanceJobPayloadBuilder,
        #[Autowire(service: 'limiter.instance_console_commands')]
        private readonly RateLimiterFactory $consoleLimiter,
        private readonly \Doctrine\ORM\EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
        private readonly ?AgentGameServerClient $agentGameServerClient = null,
        private readonly ?ConsoleStreamDiagnostics $consoleStreamDiagnostics = null,
    ) {
    }

    #[Route(path: '/api/instances/{id}/addons/install', name: 'customer_instance_addons_install', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/addons/install', name: 'customer_instance_addons_install_v1', methods: ['POST'])]
    public function installAddon(Request $request, int $id): JsonResponse
    {
        return $this->queueAddonAction($request, $id, 'install');
    }

    #[Route(path: '/api/instances/{id}/addons/remove', name: 'customer_instance_addons_remove', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/addons/remove', name: 'customer_instance_addons_remove_v1', methods: ['POST'])]
    public function removeAddon(Request $request, int $id): JsonResponse
    {
        return $this->queueAddonAction($request, $id, 'remove');
    }

    #[Route(path: '/api/instances/{id}/addons/update', name: 'customer_instance_addons_update', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/addons/update', name: 'customer_instance_addons_update_v1', methods: ['POST'])]
    public function updateAddon(Request $request, int $id): JsonResponse
    {
        return $this->queueAddonAction($request, $id, 'update');
    }

    #[Route(path: '/api/instances/{id}/power', name: 'customer_instance_power_api', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/power', name: 'customer_instance_power_api_v1', methods: ['POST'])]
    public function power(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $payload = $this->parsePayload($request);
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        $allowed = ['start', 'stop', 'restart'];
        if (!in_array($action, $allowed, true)) {
            return $this->apiError($request, 'INVALID_ACTION', 'Invalid action.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY, [
                'allowed_actions' => $allowed,
            ]);
        }

        if (!$this->appSettingsService->isGameserverStartStopAllowed()) {
            return $this->apiError($request, 'POWER_DISABLED', 'Start/Stop actions are disabled.', JsonResponse::HTTP_FORBIDDEN);
        }

        if ($instance->getStatus() === InstanceStatus::Suspended) {
            return $this->apiError($request, 'INSTANCE_SUSPENDED', 'This instance is suspended.', JsonResponse::HTTP_CONFLICT);
        }

        if (in_array($action, ['start', 'restart'], true)) {
            $preflightBlock = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
            if ($preflightBlock !== null) {
                return $this->apiError($request, 'RESOURCE_PRECHECK_FAILED', $preflightBlock, JsonResponse::HTTP_CONFLICT);
            }
        }

        $existingJob = $this->jobRepository->findLatestActiveByTypesAndInstanceId([
            'instance.start',
            'instance.stop',
            'instance.restart',
        ], $instance->getId() ?? 0);
        if ($existingJob instanceof Job) {
            $this->auditLogger->log($customer, 'instance.power.already_queued', [
                'instance_id' => $instance->getId(),
                'job_id' => $existingJob->getId(),
                'action' => $action,
            ]);

            return $this->apiOk($request, [
                'current_state' => strtolower($instance->getStatus()->value),
                'desired_state' => $action === 'stop' ? 'stopped' : 'running',
                'transition' => true,
                'job_id' => $existingJob->getId(),
            ], JsonResponse::HTTP_ACCEPTED);
        }

        $blockingLifecycleJob = $this->jobRepository->findLatestActiveByTypesAndInstanceId([
            'instance.create',
            'instance.reinstall',
            'instance.backup.create',
            'instance.backup.restore',
            'instance.settings.update',
            'instance.config.apply',
            'instance.schedule.update',
            'instance.addon.install',
            'instance.addon.update',
            'instance.addon.remove',
            'sniper.install',
            'sniper.update',
        ], $instance->getId() ?? 0);
        if ($blockingLifecycleJob instanceof Job) {
            $this->auditLogger->log($customer, 'instance.power.blocked', [
                'instance_id' => $instance->getId(),
                'action' => $action,
                'blocking_job_id' => $blockingLifecycleJob->getId(),
                'blocking_job_type' => $blockingLifecycleJob->getType(),
            ]);

            return $this->apiError(
                $request,
                'POWER_BLOCKED_BY_LIFECYCLE',
                'Power action blocked while lifecycle operation is running.',
                JsonResponse::HTTP_CONFLICT,
                [
                    'job_id' => $blockingLifecycleJob->getId(),
                    'job_type' => $blockingLifecycleJob->getType(),
                ],
            );
        }

        if ($this->agentGameServerClient instanceof AgentGameServerClient) {
            try {
                $runtimePayload = $this->agentGameServerClient->getInstanceStatus($instance);
                $runtimeStatus = $this->normalizeAgentRuntimeStatus(
                    $runtimePayload['status'] ?? null,
                    $runtimePayload['running'] ?? null,
                    $runtimePayload['online'] ?? null,
                );
                if ($runtimeStatus === InstanceStatus::Running && $action === 'start') {
                    $this->auditLogger->log($customer, 'instance.power.noop', [
                        'instance_id' => $instance->getId(),
                        'action' => $action,
                        'runtime_status' => $runtimeStatus->value,
                    ]);

                    return $this->apiOk($request, [
                        'current_state' => InstanceStatus::Running->value,
                        'desired_state' => 'running',
                        'transition' => false,
                        'message' => 'Instance is already running.',
                    ]);
                }
                if ($runtimeStatus === InstanceStatus::Stopped && $action === 'stop') {
                    $this->auditLogger->log($customer, 'instance.power.noop', [
                        'instance_id' => $instance->getId(),
                        'action' => $action,
                        'runtime_status' => $runtimeStatus->value,
                    ]);

                    return $this->apiOk($request, [
                        'current_state' => InstanceStatus::Stopped->value,
                        'desired_state' => 'stopped',
                        'transition' => false,
                        'message' => 'Instance is already stopped.',
                    ]);
                }
                if ($runtimeStatus === InstanceStatus::Stopped && $action === 'restart') {
                    return $this->apiError(
                        $request,
                        'INSTANCE_OFFLINE',
                        'Cannot restart a stopped instance. Start it instead.',
                        JsonResponse::HTTP_CONFLICT,
                    );
                }
            } catch (\Throwable $exception) {
                $this->auditLogger->log($customer, 'instance.power.runtime_probe_failed', [
                    'instance_id' => $instance->getId(),
                    'action' => $action,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $message = new InstanceActionMessage(sprintf('instance.%s', $action), $customer->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'action' => $action,
        ]);

        $response = $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
        $result = json_decode((string) $response->getContent(), true);
        if (!is_array($result) || !is_string($result['job_id'] ?? null)) {
            $this->auditLogger->log($customer, 'instance.power.queue_failed', [
                'instance_id' => $instance->getId(),
                'action' => $action,
            ]);
            return $this->apiError($request, 'POWER_QUEUE_FAILED', 'Unable to queue power action.', JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->auditLogger->log($customer, 'instance.power.queued', [
            'instance_id' => $instance->getId(),
            'action' => $action,
            'job_id' => $result['job_id'],
        ]);

        return $this->apiOk($request, [
            'current_state' => strtolower($instance->getStatus()->value),
            'desired_state' => $action === 'stop' ? 'stopped' : 'running',
            'transition' => true,
            'job_id' => $result['job_id'],
        ], JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/instances/{id}/status/fix', name: 'customer_instance_status_fix_api', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/status/fix', name: 'customer_instance_status_fix_api_v1', methods: ['POST'])]
    public function fixStatus(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $activePowerJob = $this->jobRepository->findLatestActiveByTypesAndInstanceId([
            'instance.start',
            'instance.stop',
            'instance.restart',
            'instance.reinstall',
        ], $instance->getId() ?? 0);
        if ($activePowerJob instanceof Job) {
            return $this->apiError(
                $request,
                'STATUS_FIX_BLOCKED',
                'Status sync blocked while a lifecycle job is active.',
                JsonResponse::HTTP_CONFLICT,
                ['job_id' => $activePowerJob->getId()],
            );
        }

        if (!$this->agentGameServerClient instanceof AgentGameServerClient) {
            return $this->apiError($request, 'AGENT_UNAVAILABLE', 'Agent status client is not configured.', JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $runtimePayload = $this->agentGameServerClient->getInstanceStatus($instance);
        } catch (\Throwable $exception) {
            return $this->apiError(
                $request,
                'AGENT_STATUS_PROBE_FAILED',
                sprintf('Agent status probe failed: %s', $exception->getMessage()),
                JsonResponse::HTTP_BAD_GATEWAY,
            );
        }

        $runtimeStatus = $this->normalizeAgentRuntimeStatus(
            $runtimePayload['status'] ?? null,
            $runtimePayload['running'] ?? null,
            $runtimePayload['online'] ?? null,
        );
        if ($runtimeStatus === null) {
            return $this->apiError(
                $request,
                'AGENT_STATUS_INVALID',
                'Agent status response did not contain a recognizable status.',
                JsonResponse::HTTP_BAD_GATEWAY,
            );
        }

        $previousStatus = $instance->getStatus();
        if (!in_array($previousStatus, [InstanceStatus::Suspended, InstanceStatus::PendingSetup, InstanceStatus::Provisioning], true) && $previousStatus !== $runtimeStatus) {
            $instance->setStatus($runtimeStatus);
            $this->entityManager->persist($instance);
            $this->entityManager->flush();
        }

        $this->auditLogger->log($customer, 'instance.status.fix', [
            'instance_id' => $instance->getId(),
            'previous_status' => $previousStatus->value,
            'runtime_status' => $runtimeStatus->value,
            'changed' => $previousStatus !== $instance->getStatus(),
        ]);

        return $this->apiOk($request, [
            'instance_id' => $instance->getId(),
            'previous_status' => $previousStatus->value,
            'status' => $instance->getStatus()->value,
            'runtime_status' => $runtimeStatus->value,
            'changed' => $previousStatus !== $instance->getStatus(),
            'checked_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    #[Route(path: '/api/instances/{id}/backups', name: 'customer_instance_backups_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/backups', name: 'customer_instance_backups_list_v1', methods: ['GET'])]
    public function listBackups(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $definitions = $this->backupDefinitionRepository->findByCustomer($customer);
        $matched = [];
        foreach ($definitions as $definition) {
            if ($definition->getTargetType() !== BackupTargetType::Game) {
                continue;
            }
            if ($definition->getTargetId() !== (string) $instance->getId()) {
                continue;
            }
            $matched[] = $definition;
        }

        $backups = $this->backupRepository->findByDefinitions($matched);

        $setupVars = $instance->getSetupVars();
        $mode = strtolower(trim((string) ($setupVars['EASYWI_BACKUP_MODE'] ?? 'manual')));
        if (!in_array($mode, ['auto', 'manual'], true)) {
            $mode = 'manual';
        }

        return $this->apiOk($request, [
            'mode' => $mode,
            'backups' => array_map(fn ($backup) => $this->normalizeBackup($backup), $backups),
        ]);
    }

    #[Route(path: '/api/instances/{id}/backups/mode', name: 'customer_instance_backups_mode', methods: ['PATCH'])]
    #[Route(path: '/api/v1/customer/instances/{id}/backups/mode', name: 'customer_instance_backups_mode_v1', methods: ['PATCH'])]
    public function updateBackupsMode(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $payload = $this->parsePayload($request);
        $mode = strtolower(trim((string) ($payload['mode'] ?? '')));
        if (!in_array($mode, ['auto', 'manual'], true)) {
            return $this->apiError($request, 'INVALID_INPUT', 'mode must be auto or manual.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $setupVars = $instance->getSetupVars();
        $setupVars['EASYWI_BACKUP_MODE'] = $mode;
        $instance->setSetupVars($setupVars);
        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        return $this->apiOk($request, [
            'mode' => $mode,
        ]);
    }

    #[Route(path: '/api/instances/{id}/backups', name: 'customer_instance_backups_create', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/backups', name: 'customer_instance_backups_create_v1', methods: ['POST'])]
    public function createBackup(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }
        $payload = $this->parsePayload($request);

        $definition = $this->resolveDefinition($customer, $instance, $payload);
        if ($definition === null) {
            return $this->apiError($request, 'NOT_FOUND', 'Backup definition not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $activeJob = $this->jobRepository->findLatestActiveByTypesAndInstanceId([
            'instance.backup.create',
            'instance.backup.restore',
            'instance.reinstall',
            'instance.start',
            'instance.stop',
            'instance.restart',
        ], (int) ($instance->getId() ?? 0));
        if ($activeJob instanceof Job) {
            $this->auditLogger->log($customer, 'instance.backup.blocked', [
                'instance_id' => $instance->getId(),
                'reason' => 'active_job',
                'active_job_id' => $activeJob->getId(),
                'active_job_type' => $activeJob->getType(),
            ]);

            return $this->apiError(
                $request,
                'BACKUP_CONFLICT',
                'Backup action blocked while another lifecycle job is running.',
                JsonResponse::HTTP_CONFLICT,
                ['active_job_id' => $activeJob->getId(), 'active_job_type' => $activeJob->getType()],
            );
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return $this->apiError($request, 'BACKUP_NOT_SUPPORTED', $blockMessage, JsonResponse::HTTP_CONFLICT);
        }

        $message = new InstanceActionMessage('instance.backup.create', $customer->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'definition_id' => $definition->getId(),
            'install_path' => $instance->getInstallPath(),
            'request_id' => $this->resolveRequestId($request),
        ]);

        $response = $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
        $result = json_decode((string) $response->getContent(), true);

        if (!is_array($result) || !is_string($result['job_id'] ?? null) || $result['job_id'] === '') {
            return $this->apiError($request, 'INTERNAL_ERROR', 'Unable to queue backup creation job.', JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->auditLogger->log($customer, 'instance.backup.queued', [
            'instance_id' => $instance->getId(),
            'definition_id' => $definition->getId(),
            'job_id' => $result['job_id'],
        ]);

        return $this->apiOk($request, [
                'job_id' => $result['job_id'],
                'job_type' => 'instance.backup.create',
                'backup_id' => $result['backup_id'] ?? null,
                'message' => 'Backup creation queued.',
            ], JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/instances/{id}/backups/{backupId}/restore', name: 'customer_instance_backups_restore', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/backups/{backupId}/restore', name: 'customer_instance_backups_restore_v1', methods: ['POST'])]
    public function restoreBackup(Request $request, int $id, int $backupId): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }
        $payload = $this->parsePayload($request);

        if (!(bool) ($payload['confirm'] ?? false)) {
            return $this->apiError(
                $request,
                'INVALID_INPUT',
                'Restore confirmation is required.',
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $backup = $this->backupRepository->find($backupId);
        if ($backup === null || $backup->getDefinition()->getTargetType() !== BackupTargetType::Game || $backup->getDefinition()->getTargetId() !== (string) $instance->getId()) {
            return $this->apiError($request, 'NOT_FOUND', 'Backup not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $activeJob = $this->jobRepository->findLatestActiveByTypesAndInstanceId([
            'instance.backup.create',
            'instance.backup.restore',
            'instance.reinstall',
            'instance.start',
            'instance.stop',
            'instance.restart',
        ], (int) ($instance->getId() ?? 0));
        if ($activeJob instanceof Job) {
            $this->auditLogger->log($customer, 'instance.backup.restore_blocked', [
                'instance_id' => $instance->getId(),
                'backup_id' => $backup->getId(),
                'active_job_id' => $activeJob->getId(),
                'active_job_type' => $activeJob->getType(),
            ]);

            return $this->apiError(
                $request,
                'RESTORE_CONFLICT',
                'Restore blocked while another lifecycle job is running.',
                JsonResponse::HTTP_CONFLICT,
                ['active_job_id' => $activeJob->getId(), 'active_job_type' => $activeJob->getType()],
            );
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return $this->apiError($request, 'BACKUP_NOT_SUPPORTED', $blockMessage, JsonResponse::HTTP_CONFLICT);
        }

        $message = new InstanceActionMessage('instance.backup.restore', $customer->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'backup_id' => $backup->getId(),
            'install_path' => $instance->getInstallPath(),
            'confirm' => 'true',
            'pre_backup' => (bool) ($payload['pre_backup'] ?? false) ? 'true' : 'false',
            'request_id' => $this->resolveRequestId($request),
        ]);

        $response = $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
        $result = json_decode((string) $response->getContent(), true);

        if (!is_array($result) || !is_string($result['job_id'] ?? null) || $result['job_id'] === '') {
            return $this->apiError($request, 'INTERNAL_ERROR', 'Unable to queue backup restore job.', JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->auditLogger->log($customer, 'instance.backup.restore_queued', [
            'instance_id' => $instance->getId(),
            'backup_id' => $backup->getId(),
            'job_id' => $result['job_id'],
            'pre_backup_job_id' => $result['pre_backup_job_id'] ?? null,
        ]);

        return $this->apiOk($request, [
                'job_id' => $result['job_id'],
                'job_type' => 'instance.backup.restore',
                'backup_id' => $backup->getId(),
                'pre_backup_job_id' => $result['pre_backup_job_id'] ?? null,
                'message' => 'Backup restore queued.',
            ], JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/instances/{id}/backups/{backupId}', name: 'customer_instance_backups_delete', methods: ['DELETE'])]
    #[Route(path: '/api/v1/customer/instances/{id}/backups/{backupId}', name: 'customer_instance_backups_delete_v1', methods: ['DELETE'])]
    public function deleteBackup(Request $request, int $id, int $backupId): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $backup = $this->backupRepository->find($backupId);
        if ($backup === null || $backup->getDefinition()->getTargetType() !== BackupTargetType::Game || $backup->getDefinition()->getTargetId() !== (string) $instance->getId()) {
            return $this->apiError($request, 'NOT_FOUND', 'Backup not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($backup);
        $this->entityManager->flush();

        return $this->apiOk($request, [
            'backup_id' => $backupId,
            'deleted' => true,
        ]);
    }

    #[Route(path: '/api/instances/{id}/backups/health', name: 'customer_instance_backups_health', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/backups/health', name: 'customer_instance_backups_health_v1', methods: ['GET'])]
    public function backupsHealth(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        return $this->apiOk($request, [
            'instance_id' => $instance->getId(),
            'instance_status' => strtolower($instance->getStatus()->value),
            'backup_supported' => true,
            'supports_backup_download' => true,
            'supports_backup_mode' => true,
        ]);
    }

    #[Route(path: '/api/instances/{id}/backups/{backupId}/download', name: 'customer_instance_backups_download', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/backups/{backupId}/download', name: 'customer_instance_backups_download_v1', methods: ['GET'])]
    public function downloadBackup(Request $request, int $id, int $backupId): JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $backup = $this->backupRepository->find($backupId);
        if ($backup === null || $backup->getDefinition()->getTargetType() !== BackupTargetType::Game || $backup->getDefinition()->getTargetId() !== (string) $instance->getId()) {
            return $this->apiError($request, 'NOT_FOUND', 'Backup not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        if ($backup->getStatus() !== BackupStatus::Succeeded) {
            return $this->apiError($request, 'CONFLICT', 'Backup is not ready for download.', JsonResponse::HTTP_CONFLICT);
        }

        $archivePath = trim((string) $backup->getArchivePath());
        if ($archivePath === '' || !is_file($archivePath) || !is_readable($archivePath)) {
            return $this->apiError($request, 'NOT_FOUND', 'Backup archive is unavailable.', JsonResponse::HTTP_NOT_FOUND);
        }

        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($archivePath);
        $response->setContentDisposition(
            \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('instance-%d-backup-%d.tar.gz', $instance->getId(), $backup->getId()),
        );
        $response->headers->set('X-Request-ID', $this->resolveRequestId($request));

        return $response;
    }

    #[Route(path: '/api/instances/{id}/reinstall/options', name: 'customer_instance_reinstall_api_options', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/reinstall/options', name: 'customer_instance_reinstall_api_options_v1', methods: ['GET'])]
    public function reinstallOptions(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $options = [];
        $versions = [];
        $resolver = $instance->getTemplate()->getInstallResolver();
        $type = is_array($resolver) ? (string) ($resolver['type'] ?? '') : '';
        if ($type === 'minecraft_vanilla') {
            $versions = $this->minecraftCatalogService->getUiCatalog()['vanilla']['versions'] ?? [];
        } elseif ($type === 'papermc_paper') {
            $versions = $this->minecraftCatalogService->getUiCatalog()['paper']['versions'] ?? [];
        }

        foreach ($versions as $version) {
            if (!is_string($version) || trim($version) === '') {
                continue;
            }
            $options[] = [
                'id' => $version,
                'label' => $version,
                'version' => $version,
            ];
        }
        if ($options === []) {
            $fallback = $instance->getCurrentVersion() ?? 'default';
            $options[] = ['id' => $fallback, 'label' => $fallback, 'version' => $fallback];
        }

        return $this->apiOk($request, [
            'instance_id' => $instance->getId(),
            'warnings' => ['Neuinstallation löscht bestehende Daten dieser Instanz.'],
            'options' => $options,
        ]);
    }



    #[Route(path: '/api/instances/{id}/tasks', name: 'customer_instance_tasks_api_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/tasks', name: 'customer_instance_tasks_api_list_v1', methods: ['GET'])]
    public function listTasksEnvelope(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $tasks = [];
        foreach ($this->jobRepository->findLatest(100) as $job) {
            $payload = $job->getPayload();
            if ((string) ($payload['instance_id'] ?? '') !== (string) $instance->getId()) {
                continue;
            }
            $tasks[] = [
                'id' => $job->getId(),
                'type' => $job->getType(),
                'status' => $job->getStatus()->value,
                'created_at' => $job->getCreatedAt()->format(DATE_ATOM),
            ];
        }

        return $this->apiOk($request, ['tasks' => array_slice($tasks, 0, 25)]);
    }

    #[Route(path: '/api/instances/{id}/tasks/{taskId}/cancel', name: 'customer_instance_tasks_api_cancel', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/tasks/{taskId}/cancel', name: 'customer_instance_tasks_api_cancel_v1', methods: ['POST'])]
    public function cancelTaskEnvelope(Request $request, int $id, string $taskId): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $job = $this->jobRepository->find($taskId);
        if (!$job instanceof Job) {
            return $this->apiError($request, 'NOT_FOUND', 'Task not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $job->getPayload();
        if ((string) ($payload['instance_id'] ?? '') !== (string) $instance->getId()) {
            return $this->apiError($request, 'FORBIDDEN', 'Forbidden.', JsonResponse::HTTP_FORBIDDEN);
        }

        if ($job->getStatus()->isTerminal()) {
            return $this->apiError($request, 'INVALID_INPUT', 'Task already finished.', JsonResponse::HTTP_CONFLICT);
        }

        $job->transitionTo(\App\Module\Core\Domain\Enum\JobStatus::Cancelled);
        $job->clearLock();
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->apiOk($request, [
            'task_id' => $job->getId(),
            'status' => $job->getStatus()->value,
            'cancelled' => true,
        ]);
    }

    #[Route(path: '/api/instances/{id}/tasks/logs', name: 'customer_instance_tasks_api_logs', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/tasks/logs', name: 'customer_instance_tasks_api_logs_v1', methods: ['GET'])]
    public function taskLogsEnvelope(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $taskId = (string) $request->query->get('task_id', '');
        if ($taskId === '') {
            return $this->apiError($request, 'INVALID_INPUT', 'task_id is required.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $job = $this->jobRepository->find($taskId);
        if (!$job instanceof Job) {
            return $this->apiError($request, 'NOT_FOUND', 'Task not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $job->getPayload();
        if ((string) ($payload['instance_id'] ?? '') !== (string) $instance->getId()) {
            return $this->apiError($request, 'FORBIDDEN', 'Forbidden.', JsonResponse::HTTP_FORBIDDEN);
        }

        $cursor = $request->query->get('cursor');
        $afterId = is_numeric($cursor) ? (int) $cursor : null;
        $logs = $this->jobLogRepository->findByJobAfterId($job, $afterId);
        $normalized = array_map(static fn ($log): array => [
            'id' => $log->getId(),
            'message' => $log->getMessage(),
            'progress' => $log->getProgress(),
            'created_at' => $log->getCreatedAt()->format(DATE_ATOM),
        ], $logs);
        $lastLog = $normalized === [] ? null : $normalized[array_key_last($normalized)];
        $nextCursor = is_array($lastLog) ? (string) ($lastLog['id'] ?? '') : (string) ($cursor ?? '');

        return $this->apiOk($request, ['logs' => $normalized, 'cursor' => $nextCursor]);
    }

    #[Route(path: '/api/instances/{id}/tasks/health', name: 'customer_instance_tasks_api_health', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/tasks/health', name: 'customer_instance_tasks_api_health_v1', methods: ['GET'])]
    public function tasksHealthEnvelope(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        return $this->apiOk($request, [
            'instance_id' => $instance->getId(),
            'instance_status' => strtolower($instance->getStatus()->value),
            'tasks_supported' => true,
        ]);
    }

    #[Route(path: '/api/instances/{id}/schedules', name: 'customer_instance_schedule_update_envelope', methods: ['PATCH'])]
    #[Route(path: '/api/v1/customer/instances/{id}/schedules', name: 'customer_instance_schedule_update_envelope_v1', methods: ['PATCH'])]
    public function updateScheduleEnvelope(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $payload = $this->parsePayload($request);
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        $scheduleAction = InstanceScheduleAction::tryFrom($action);
        if ($scheduleAction === null) {
            return $this->apiError($request, 'INVALID_INPUT', 'Invalid schedule action.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cronExpression = trim((string) ($payload['cron_expression'] ?? ''));
        $timeZone = trim((string) ($payload['time_zone'] ?? 'UTC'));
        $enabled = (bool) ($payload['enabled'] ?? true);

        if ($enabled && $cronExpression === '') {
            return $this->apiError($request, 'INVALID_INPUT', 'Cron expression is required.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($enabled && !CronExpression::isValidExpression($cronExpression)) {
            return $this->apiError($request, 'INVALID_INPUT', 'Cron expression is invalid.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            new \DateTimeZone($timeZone === '' ? 'UTC' : $timeZone);
        } catch (\Exception) {
            return $this->apiError($request, 'INVALID_INPUT', 'Time zone is invalid.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message = new InstanceActionMessage('instance.schedule.update', $customer->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'action' => $scheduleAction->value,
            'cron_expression' => $cronExpression,
            'time_zone' => $timeZone,
            'enabled' => $enabled,
        ]);

        $response = $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
        $raw = json_decode((string) $response->getContent(), true);
        if (!is_array($raw) || !isset($raw['job_id'])) {
            return $this->apiError($request, 'INTERNAL_ERROR', 'Unable to queue schedule update.', JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->apiOk($request, [
            'job_id' => $raw['job_id'],
            'action' => $scheduleAction->value,
        ], JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/instances/{id}/schedules/{action}', name: 'customer_instance_schedule_update', methods: ['PATCH'])]
    #[Route(path: '/api/v1/customer/instances/{id}/schedules/{action}', name: 'customer_instance_schedule_update_v1', methods: ['PATCH'])]
    public function updateSchedule(Request $request, int $id, string $action): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $payload = $this->parsePayload($request);

        $scheduleAction = InstanceScheduleAction::tryFrom($action);
        if ($scheduleAction === null) {
            return $this->responseEnvelopeFactory->error($request, 'Invalid schedule action.', 'schedule_action_invalid', JsonResponse::HTTP_BAD_REQUEST);
        }

        $cronExpression = trim((string) ($payload['cron_expression'] ?? ''));
        $timeZone = trim((string) ($payload['time_zone'] ?? 'UTC'));
        $enabled = (bool) ($payload['enabled'] ?? true);

        if ($enabled && $cronExpression === '') {
            return $this->responseEnvelopeFactory->error($request, 'Cron expression is required.', 'schedule_cron_required', JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($enabled && !CronExpression::isValidExpression($cronExpression)) {
            return $this->responseEnvelopeFactory->error($request, 'Cron expression is invalid.', 'schedule_cron_invalid', JsonResponse::HTTP_BAD_REQUEST);
        }

        $timeZone = $timeZone === '' ? 'UTC' : $timeZone;
        try {
            new \DateTimeZone($timeZone);
        } catch (\Exception) {
            return $this->responseEnvelopeFactory->error($request, 'Time zone is invalid.', 'schedule_timezone_invalid', JsonResponse::HTTP_BAD_REQUEST);
        }

        $active = $this->jobRepository->findLatestActiveByTypesAndInstanceId(['instance.schedule.update'], $instance->getId() ?? 0);
        if ($active instanceof Job) {
            return $this->responseEnvelopeFactory->success(
                $request,
                $active->getId(),
                'Schedule update already in progress.',
                JsonResponse::HTTP_ACCEPTED,
            );
        }

        $message = new InstanceActionMessage('instance.schedule.update', $customer->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'action' => $scheduleAction->value,
            'cron_expression' => $cronExpression,
            'time_zone' => $timeZone,
            'enabled' => $enabled,
        ]);

        return $this->dispatchJob($message);
    }

    #[Route(path: '/api/instances/{id}/console/commands', name: 'customer_instance_console_command', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/console/commands', name: 'customer_instance_console_command_v1', methods: ['POST'])]
    public function sendConsoleCommand(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $payload = $this->parsePayload($request);
        $command = trim((string) ($payload['command'] ?? ''));

        $limitResult = $this->consumeConsoleLimiter($request, $instance, $customer);
        if ($limitResult['accepted'] === false) {
            return $this->responseEnvelopeFactory->error(
                $request,
                'Console command rate limit exceeded. Please retry shortly.',
                'console_rate_limited',
                JsonResponse::HTTP_TOO_MANY_REQUESTS,
                $limitResult['retry_after'],
            );
        }

        if ($command === '') {
            return $this->responseEnvelopeFactory->error(
                $request,
                'Command is required.',
                'console_command_required',
                JsonResponse::HTTP_BAD_REQUEST,
            );
        }
        $validationError = $this->consoleCommandValidator->validate($command);
        if ($validationError !== null) {
            return $this->responseEnvelopeFactory->error(
                $request,
                $validationError,
                'console_command_invalid',
                JsonResponse::HTTP_BAD_REQUEST,
            );
        }

        $runtimeProbe = $this->resolveConsoleRuntimeProbe($instance);
        if (!$runtimeProbe['running']) {
            return $this->responseEnvelopeFactory->error(
                $request,
                'Instance must be running to send commands.',
                'instance_not_running',
                JsonResponse::HTTP_BAD_REQUEST,
            );
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return $this->responseEnvelopeFactory->error(
                $request,
                $blockMessage,
                'disk_quota_blocked',
                JsonResponse::HTTP_BAD_REQUEST,
            );
        }

        $commandHash = hash('sha256', $command);
        $this->auditLogger->log($customer, 'instance.console.command_requested', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'command_hash' => $commandHash,
            'command_length' => strlen($command),
            'request_id' => $this->resolveRequestId($request),
        ]);

        $message = new InstanceActionMessage('instance.console.command', $customer->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'instance_root' => $this->gameServerPathResolver->resolveRoot($instance),
            'command' => $command,
            'command_hash' => $commandHash,
            'command_length' => strlen($command),
        ]);

        $response = $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
        $data = json_decode((string) $response->getContent(), true);
        if (is_array($data) && is_string($data['job_id'] ?? null)) {
            $this->auditLogger->log($customer, 'instance.console.command_queued', [
                'instance_id' => $instance->getId(),
                'customer_id' => $customer->getId(),
                'job_id' => $data['job_id'],
                'command_hash' => $commandHash,
                'command_length' => strlen($command),
                'request_id' => $this->resolveRequestId($request),
            ]);
        }

        return $response;
    }

    #[Route(path: '/api/instances/{id}/console/command', name: 'customer_instance_console_command_envelope', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/console/command', name: 'customer_instance_console_command_envelope_v1', methods: ['POST'])]
    public function sendConsoleCommandEnvelope(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $payload = $this->parsePayload($request);
        $command = trim((string) ($payload['command'] ?? ''));
        if ($command === '') {
            return $this->apiError($request, 'INVALID_INPUT', 'Command is required.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $legacy = $this->sendConsoleCommand($request, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        return $this->mapLegacyConsoleResponse($request, $legacy);
    }

    #[Route(path: '/api/instances/{id}/console/logs', name: 'customer_instance_console_logs_envelope', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/console/logs', name: 'customer_instance_console_logs_envelope_v1', methods: ['GET'])]
    public function logsEnvelope(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $cursorRaw = trim((string) $request->query->get('cursor', ''));

        if ($this->agentGameServerClient instanceof AgentGameServerClient) {
            try {
                $agentPayload = $this->agentGameServerClient->getConsoleLogs($instance, $cursorRaw !== '' ? $cursorRaw : null);
                $data = is_array($agentPayload['data'] ?? null) ? $agentPayload['data'] : [];
                $rawLines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
                $mapped = [];
                foreach ($rawLines as $line) {
                    if (!is_array($line)) {
                        continue;
                    }
                    $mapped[] = [
                        'id' => (int) ($line['id'] ?? 0),
                        'message' => (string) ($line['text'] ?? ''),
                        'created_at' => (string) ($line['ts'] ?? (new \DateTimeImmutable())->format(DATE_ATOM)),
                        'progress' => null,
                    ];
                }

                $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
                $connected = strtolower((string) ($meta['state'] ?? '')) === 'connected';
                $journalAvailable = (bool) ($meta['journal_available'] ?? true);
                $agentUnavailable = !$journalAvailable || strtolower((string) ($meta['state'] ?? '')) === 'unavailable';

                if ($agentUnavailable && $mapped === []) {
                    throw new \RuntimeException('agent console stream unavailable');
                }

                return $this->apiOk($request, [
                    'cursor' => (string) ($data['cursor'] ?? $cursorRaw),
                    'lines' => $mapped,
                    'session' => [
                        'connected' => $connected,
                        'unit_name' => (string) ($meta['unit'] ?? sprintf('gs-%d', $instance->getId())),
                        'started_at' => null,
                    ],
                ]);
            } catch (\Throwable) {
                // fallback to persisted job logs for legacy environments.
            }
        }

        $job = $this->resolveConsoleLogsJob($instance, $customer);
        if ($job instanceof Job) {
            $cursor = $cursorRaw !== '' && ctype_digit($cursorRaw) ? (int) $cursorRaw : null;
            $logs = $this->jobLogRepository->findByJobAfterId($job, $cursor, 300);
            $nextCursor = $cursor ?? 0;
            $lines = [];
            $unitName = sprintf('gs-%d', $instance->getId());
            foreach ($logs as $log) {
                $logId = (int) ($log->getId() ?? 0);
                $nextCursor = max($nextCursor, $logId);
                $message = (string) $log->getMessage();
                if (str_starts_with($message, '--- journalctl ') || stripos($message, 'console restarted') !== false) {
                    continue;
                }

                $lines[] = [
                    'id' => $logId,
                    'message' => $message,
                    'created_at' => $log->getCreatedAt()->format(DATE_ATOM),
                    'progress' => $log->getProgress(),
                ];
            }

            return $this->apiOk($request, [
                'job_id' => $job->getId(),
                'job_type' => $job->getType(),
                'status' => $job->getStatus()->value,
                'cursor' => $nextCursor,
                'lines' => $lines,
                'session' => [
                    'connected' => true,
                    'unit_name' => $unitName,
                    'started_at' => $job->getCreatedAt()->format(DATE_ATOM),
                ],
            ]);
        }

        return $this->apiError(
            $request,
            'INSTANCE_OFFLINE',
            'No active or recent console source job found.',
            JsonResponse::HTTP_CONFLICT,
        );
    }

    #[Route(path: '/api/instances/{id}/console/health', name: 'customer_instance_console_health_envelope', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/console/health', name: 'customer_instance_console_health_envelope_v1', methods: ['GET'])]
    public function healthEnvelope(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $runtimeProbe = $this->resolveConsoleRuntimeProbe($instance);
        $runtimeStatus = $runtimeProbe['runtime_status'];
        $running = $runtimeProbe['running'];

        $supportsLiveOutput = true;
        $liveOutputStatus = 'ok';
        $liveOutputMessage = null;

        if (isset($this->consoleStreamDiagnostics) && $this->consoleStreamDiagnostics instanceof ConsoleStreamDiagnostics) {
            if ($this->consoleStreamDiagnostics->isNullClient()) {
                $supportsLiveOutput = false;
                $liveOutputStatus = 'backend_not_configured';
                $liveOutputMessage = 'Console backend not configured.';
            } elseif (!$this->consoleStreamDiagnostics->redisPingOk()) {
                $supportsLiveOutput = false;
                $liveOutputStatus = 'redis_unavailable';
                $liveOutputMessage = 'Redis unavailable.';
            } else {
                $relayAge = $this->consoleStreamDiagnostics->relayHeartbeatAgeSeconds();
                if ($relayAge === null || $relayAge > 20) {
                    $supportsLiveOutput = false;
                    $liveOutputStatus = 'relay_stale';
                    $liveOutputMessage = 'Console relay offline.';
                }
            }
        }

        return $this->apiOk($request, [
            'instance_id' => $instance->getId(),
            'instance_status' => strtolower($instance->getStatus()->value),
            'runtime_status' => $runtimeStatus,
            'can_send_command' => $running,
            'unit_name' => sprintf('gs-%d', $instance->getId()),
            'running_state' => $running ? 'running' : 'offline',
            'supports_live_output' => $supportsLiveOutput,
            'live_output_status' => $liveOutputStatus,
            'live_output_message' => $liveOutputMessage,
            'supports_command_injection' => true,
            'injection_mechanism' => 'unix_socket',
            'session_active' => $running,
        ]);
    }

    #[Route(path: '/api/instances/{id}/console/logs', name: 'customer_instance_console_logs', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/console/logs', name: 'customer_instance_console_logs_v1', methods: ['POST'])]
    public function requestConsoleLogs(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $job = $this->resolveConsoleLogsJob($instance, $customer);

        if ($job === null) {
            return new JsonResponse(['error' => 'No recent install/start job found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'job_id' => $job->getId(),
            'job_type' => $job->getType(),
        ], JsonResponse::HTTP_OK);
    }

    #[Route(path: '/api/instances/{id}/settings', name: 'customer_instance_settings_update', methods: ['PATCH'])]
    #[Route(path: '/api/v1/customer/instances/{id}/settings', name: 'customer_instance_settings_update_v1', methods: ['PATCH'])]
    public function updateSettings(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $payload = $this->parsePayload($request);

        $policyRaw = (string) ($payload['update_policy'] ?? InstanceUpdatePolicy::Manual->value);
        $policy = InstanceUpdatePolicy::tryFrom($policyRaw);
        if ($policy === null) {
            return $this->responseEnvelopeFactory->error($request, 'Invalid update policy.', 'update_policy_invalid', JsonResponse::HTTP_BAD_REQUEST);
        }

        $cronExpression = trim((string) ($payload['cron_expression'] ?? ''));
        $timeZone = trim((string) ($payload['time_zone'] ?? 'UTC'));
        if ($policy === InstanceUpdatePolicy::Auto && $cronExpression === '') {
            return $this->responseEnvelopeFactory->error($request, 'Auto updates require a cron schedule.', 'update_cron_required', JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($policy === InstanceUpdatePolicy::Auto && !CronExpression::isValidExpression($cronExpression)) {
            return $this->responseEnvelopeFactory->error($request, 'Cron expression is invalid.', 'update_cron_invalid', JsonResponse::HTTP_BAD_REQUEST);
        }

        $timeZone = $timeZone === '' ? 'UTC' : $timeZone;
        try {
            new \DateTimeZone($timeZone);
        } catch (\Exception) {
            return $this->responseEnvelopeFactory->error($request, 'Time zone is invalid.', 'update_timezone_invalid', JsonResponse::HTTP_BAD_REQUEST);
        }

        $active = $this->jobRepository->findLatestActiveByTypesAndInstanceId(['instance.settings.update'], $instance->getId() ?? 0);
        if ($active instanceof Job) {
            return $this->responseEnvelopeFactory->success(
                $request,
                $active->getId(),
                'Settings update already in progress.',
                JsonResponse::HTTP_ACCEPTED,
            );
        }

        $message = new InstanceActionMessage('instance.settings.update', $customer->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'update_policy' => $policy->value,
            'locked_build_id' => trim((string) ($payload['locked_build_id'] ?? '')),
            'locked_version' => trim((string) ($payload['locked_version'] ?? '')),
            'cron_expression' => $cronExpression,
            'time_zone' => $timeZone,
        ]);

        return $this->dispatchJob($message);
    }

    #[Route(path: '/api/instances/{id}/reinstall', name: 'customer_instance_reinstall_api', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/reinstall', name: 'customer_instance_reinstall_api_v1', methods: ['POST'])]
    public function reinstall(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $payload = $this->parsePayload($request);

        if (!(bool) ($payload['confirm'] ?? false)) {
            return $this->apiError($request, 'INVALID_INPUT', 'Confirmation is required.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $status = $this->setupChecker->getSetupStatus($instance);
        if (!$status['is_ready'] && in_array(SetupChecker::ACTION_INSTALL, $status['blocked_actions'], true)) {
            return $this->apiError($request, 'CONFLICT', 'Setup requirements missing.', JsonResponse::HTTP_CONFLICT, [
                'missing' => $status['missing'],
            ]);
        }

        $activeJob = $this->jobRepository->findLatestActiveByTypesAndInstanceId([
            'instance.reinstall',
            'instance.backup.create',
            'instance.backup.restore',
            'instance.start',
            'instance.stop',
            'instance.restart',
            'instance.config.apply',
            'instance.settings.update',
            'instance.addon.install',
            'instance.addon.update',
            'instance.addon.remove',
            'sniper.update',
        ], (int) ($instance->getId() ?? 0));
        if ($activeJob instanceof Job) {
            return $this->apiError(
                $request,
                'REINSTALL_CONFLICT',
                'Reinstall blocked while another lifecycle job is running.',
                JsonResponse::HTTP_CONFLICT,
                ['active_job_id' => $activeJob->getId(), 'active_job_type' => $activeJob->getType()],
            );
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return $this->apiError($request, 'CONFLICT', $blockMessage, JsonResponse::HTTP_CONFLICT);
        }

        $selectedVersion = trim((string) ($payload['version'] ?? ''));
        if ($selectedVersion !== '') {
            $instance->setLockedVersion($selectedVersion);
            $this->entityManager->persist($instance);
            $this->entityManager->flush();
        }

        $payload = $this->instanceJobPayloadBuilder->buildSniperInstallPayload($instance);
        $payload['autostart'] = 'false';

        $message = new InstanceActionMessage('instance.reinstall', $customer->getId(), $instance->getId(), $payload);

        $response = $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
        $result = json_decode((string) $response->getContent(), true);
        if (!is_array($result) || !is_string($result['job_id'] ?? null)) {
            return $this->apiError($request, 'INTERNAL_ERROR', 'Unable to queue reinstall job.', JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->apiOk($request, [
            'job_id' => $result['job_id'],
            'job_type' => 'instance.reinstall',
            'status' => 'queued',
        ], JsonResponse::HTTP_ACCEPTED);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (
            !$actor instanceof User
            || (!$actor->isAdmin() && $actor->getType() !== UserType::Customer)
        ) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function findCustomerInstance(User $customer, int $id): Instance
    {
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new NotFoundHttpException('Instance not found.');
        }

        if (!$customer->isAdmin() && $instance->getCustomer()->getId() !== $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $instance;
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePayload(Request $request): array
    {
        try {
            $payload = $request->toArray();
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Invalid JSON payload.', $exception);
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchJob(InstanceActionMessage $message, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        $envelope = $this->messageBus->dispatch($message);
        $handled = $envelope->last(HandledStamp::class);
        $result = $handled?->getResult();

        if (!is_array($result)) {
            return new JsonResponse(['status' => 'queued'], $status);
        }

        return new JsonResponse($result, $status);
    }


    /**
     * @param 'install'|'update'|'remove' $action
     */
    private function queueAddonAction(Request $request, int $id, string $action): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $payload = $this->parsePayload($request);

        $pluginId = $payload['plugin_id'] ?? null;
        if (!is_int($pluginId) && !is_string($pluginId)) {
            return $this->responseEnvelopeFactory->error(
                $request,
                'plugin_id is required.',
                'addon_plugin_id_required',
                JsonResponse::HTTP_BAD_REQUEST,
            );
        }

        $plugin = $this->gamePluginRepository->find((int) $pluginId);
        if ($plugin === null || !$this->isPluginAssignableToInstance($plugin, $instance)) {
            return $this->responseEnvelopeFactory->error(
                $request,
                'Plugin not found for this instance.',
                'addon_plugin_not_found',
                JsonResponse::HTTP_NOT_FOUND,
            );
        }

        $incompatibility = $this->resolveAddonIncompatibility($instance);
        if ($incompatibility !== null) {
            return $this->responseEnvelopeFactory->error(
                $request,
                'Addon is incompatible with this instance runtime.',
                'addon_incompatible',
                JsonResponse::HTTP_CONFLICT,
                null,
                ['details' => $incompatibility],
            );
        }

        $activeAddonJob = $this->findActiveAddonJob($instance);
        if ($activeAddonJob !== null) {
            $activePayload = $activeAddonJob->getPayload();
            $activePluginId = (int) ($activePayload['plugin_id'] ?? 0);
            $activeAction = $this->addonActionFromJobType($activeAddonJob->getType());
            if ($activeAction === $action && $activePluginId === $plugin->getId()) {
                return $this->responseEnvelopeFactory->success(
                    $request,
                    $activeAddonJob->getId(),
                    'Addon action already in progress.',
                    JsonResponse::HTTP_ACCEPTED,
                    ['retry_after' => 10],
                );
            }

            return $this->responseEnvelopeFactory->error(
                $request,
                'Another addon action is already running.',
                'addon_action_in_progress',
                JsonResponse::HTTP_CONFLICT,
                10,
                ['active_job_id' => $activeAddonJob->getId()],
            );
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return $this->responseEnvelopeFactory->error(
                $request,
                $blockMessage,
                'disk_quota_blocked',
                JsonResponse::HTTP_BAD_REQUEST,
            );
        }

        $payload = [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'plugin_id' => (string) $plugin->getId(),
            'plugin_name' => $plugin->getName(),
            'plugin_version' => $plugin->getVersion(),
            'plugin_checksum' => $plugin->getChecksum(),
            'plugin_download_url' => $plugin->getDownloadUrl(),
        ] + $this->buildCs2MetamodGameInfoPatchPayload($instance, $plugin, $action);

        $message = new InstanceActionMessage(sprintf('instance.addon.%s', $action), $customer->getId(), $instance->getId(), $payload);

        $response = $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
        $result = json_decode((string) $response->getContent(), true);
        if (!is_array($result) || !is_string($result['job_id'] ?? null)) {
            return $this->responseEnvelopeFactory->error(
                $request,
                'Unable to queue addon action.',
                'addon_queue_failed',
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return $this->responseEnvelopeFactory->success(
            $request,
            $result['job_id'],
            'Addon action queued.',
            JsonResponse::HTTP_ACCEPTED,
            ['action' => $action],
        );
    }


    /**
     * @return array<string, mixed>
     */
    private function buildCs2MetamodGameInfoPatchPayload(Instance $instance, \App\Module\Core\Domain\Entity\GamePlugin $plugin, string $action): array
    {
        $gameKey = strtolower(trim($instance->getTemplate()->getGameKey()));
        $pluginName = strtolower(trim($plugin->getName()));
        if ($gameKey !== 'cs2' || $action === 'remove' || !str_contains($pluginName, 'metamod')) {
            return [];
        }

        return [
            'post_install_file_patches' => [[
                'path' => 'game/csgo/gameinfo.gi',
                'mode' => 'ensure_line_between',
                'line' => 'Game	csgo/addons/metamod',
                'after' => 'Game_LowViolence	csgo_lv',
                'before' => 'Game	csgo',
                'reapply_on_update' => true,
            ]],
        ];
    }

    private function isPluginAssignableToInstance(\App\Module\Core\Domain\Entity\GamePlugin $plugin, Instance $instance): bool
    {
        $pluginTemplate = $plugin->getTemplate();
        $instanceTemplate = $instance->getTemplate();

        if ($pluginTemplate->getId() !== null && $instanceTemplate->getId() !== null && $pluginTemplate->getId() === $instanceTemplate->getId()) {
            return true;
        }

        return $this->normalizeGameKey($pluginTemplate->getGameKey()) === $this->normalizeGameKey($instanceTemplate->getGameKey());
    }

    private function normalizeGameKey(string $gameKey): string
    {
        return mb_strtolower(trim($gameKey));
    }

    private function findActiveAddonJob(Instance $instance): ?Job
    {
        $activeJob = $this->jobRepository->findLatestActiveByTypesAndInstanceId([
            'instance.addon.install',
            'instance.addon.update',
            'instance.addon.remove',
        ], $instance->getId() ?? 0);

        if ($activeJob === null) {
            return null;
        }

        return $activeJob;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveAddonIncompatibility(Instance $instance): ?array
    {
        $supportedOs = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $instance->getTemplate()->getSupportedOs(),
        ), static fn (string $value): bool => $value !== ''));
        if ($supportedOs === []) {
            return null;
        }

        $nodeMetadata = $instance->getNode()->getMetadata() ?? [];
        $heartbeatStats = $instance->getNode()->getLastHeartbeatStats() ?? [];
        $nodeOs = strtolower(trim((string) ($nodeMetadata['os'] ?? $heartbeatStats['os'] ?? '')));
        if ($nodeOs === '' || in_array($nodeOs, $supportedOs, true)) {
            return null;
        }

        return [
            'required_game' => $instance->getTemplate()->getGameKey(),
            'required_build' => $instance->getCurrentBuildId(),
            'required_os' => $supportedOs,
            'actual_os' => $nodeOs,
        ];
    }

    private function addonActionFromJobType(string $jobType): ?string
    {
        return match ($jobType) {
            'instance.addon.install' => 'install',
            'instance.addon.update' => 'update',
            'instance.addon.remove' => 'remove',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeBackup(\App\Module\Core\Domain\Entity\Backup $backup): array
    {
        return [
            'id' => $backup->getId(),
            'definition_id' => $backup->getDefinition()->getId(),
            'status' => $backup->getStatus()->value,
            'job_id' => $backup->getJob()?->getId(),
            'created_at' => $backup->getCreatedAt()->format(DATE_ATOM),
            'completed_at' => $backup->getCompletedAt()?->format(DATE_ATOM),
            'size_bytes' => $backup->getSizeBytes(),
            'checksum_sha256' => $backup->getChecksumSha256(),
            'archive_path' => $backup->getArchivePath(),
            'error_code' => $backup->getErrorCode(),
            'error_message' => $backup->getErrorMessage(),
        ];
    }

    private function resolveDefinition(User $customer, Instance $instance, array $payload): ?BackupDefinition
    {
        $definitionId = $payload['definition_id'] ?? null;
        if (is_int($definitionId) || is_string($definitionId)) {
            $definition = $this->backupDefinitionRepository->find((int) $definitionId);
            if ($definition === null) {
                return null;
            }
            if ($definition->getCustomer()->getId() !== $customer->getId()) {
                return null;
            }
            if ($definition->getTargetType() !== BackupTargetType::Game || $definition->getTargetId() !== (string) $instance->getId()) {
                return null;
            }

            return $definition;
        }

        $label = isset($payload['label']) ? trim((string) $payload['label']) : null;
        $definition = new BackupDefinition($customer, BackupTargetType::Game, (string) $instance->getId(), $label !== '' ? $label : null);
        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        return $definition;
    }

    private function findLatestConsoleJob(Instance $instance): ?\App\Module\Core\Domain\Entity\Job
    {
        $types = [
            'sniper.install',
            'instance.reinstall',
            'instance.start',
            'instance.stop',
            'instance.restart',
        ];

        $jobs = $this->jobRepository->findLatest(200);
        foreach ($jobs as $job) {
            if (!in_array($job->getType(), $types, true)) {
                continue;
            }
            $payload = $job->getPayload();
            if ((string) ($payload['instance_id'] ?? '') !== (string) $instance->getId()) {
                continue;
            }

            return $job;
        }

        return null;
    }

    private function findLatestLogTailJob(Instance $instance): ?\App\Module\Core\Domain\Entity\Job
    {
        $jobs = $this->jobRepository->findLatest(200);
        foreach ($jobs as $job) {
            if ($job->getType() !== 'instance.logs.tail') {
                continue;
            }
            $payload = $job->getPayload();
            if ((string) ($payload['instance_id'] ?? '') !== (string) $instance->getId()) {
                continue;
            }

            return $job;
        }

        return null;
    }

    private function resolveConsoleLogsJob(Instance $instance, User $customer): ?Job
    {
        if ($instance->getStatus() === InstanceStatus::Running) {
            $job = $this->findLatestLogTailJob($instance);
            if ($job === null || $job->getStatus()->isTerminal()) {
                $job = new Job('instance.logs.tail', [
                    'instance_id' => (string) $instance->getId(),
                    'customer_id' => (string) $customer->getId(),
                    'node_id' => $instance->getNode()->getId(),
                    'agent_id' => $instance->getNode()->getId(),
                ]);
                $this->entityManager->persist($job);
                $this->entityManager->flush();
            }

            return $job;
        }

        return $this->findLatestLogTailJob($instance) ?? $this->findLatestConsoleJob($instance);
    }

    /**
     * @return array{accepted: bool, retry_after: int}
     */
    private function consumeConsoleLimiter(Request $request, Instance $instance, User $customer): array
    {
        $limiter = $this->consoleLimiter->create($this->buildConsoleLimiterKey($request, $instance, $customer));
        $result = $limiter->consume();
        $retryAfter = $result->getRetryAfter();
        $retrySeconds = $retryAfter === null ? 1 : max(1, $retryAfter->getTimestamp() - (new \DateTimeImmutable())->getTimestamp());

        return [
            'accepted' => $result->isAccepted(),
            'retry_after' => $retrySeconds,
        ];
    }

    private function buildConsoleLimiterKey(Request $request, Instance $instance, User $customer): string
    {
        $ip = (string) ($request->getClientIp() ?? 'unknown');

        return sprintf('console:%d:%d:%s', $customer->getId(), $instance->getId(), $ip);
    }

    private function resolveRequestId(Request $request): string
    {
        $header = trim((string) ($request->headers->get('X-Request-ID') ?? ''));
        if ($header !== '') {
            return $header;
        }

        return trim((string) ($request->attributes->get('request_id') ?? ''));
    }

    private function mapLegacyConsoleResponse(Request $request, JsonResponse $legacy): JsonResponse
    {
        $statusCode = $legacy->getStatusCode();
        $payload = json_decode((string) $legacy->getContent(), true);
        if (!is_array($payload)) {
            return $this->apiError($request, 'INTERNAL_ERROR', 'Invalid console response payload.', JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        $legacyErrorCode = is_string($payload['error_code'] ?? null) ? $payload['error_code'] : null;
        if ($statusCode >= 400 || $legacyErrorCode !== null || isset($payload['error'])) {
            $message = (string) ($payload['message'] ?? $payload['error'] ?? 'Console command failed.');
            $normalized = $this->normalizeConsoleErrorCode($legacyErrorCode, $statusCode);

            return $this->apiError($request, $normalized, $message, $statusCode);
        }

        $jobId = is_string($payload['job_id'] ?? null) ? $payload['job_id'] : null;
        if ($jobId === null || $jobId === '') {
            return $this->apiError($request, 'INTERNAL_ERROR', 'Console command queue result missing job id.', JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->apiOk($request, [
            'job_id' => $jobId,
            'status' => (string) ($payload['status'] ?? 'queued'),
            'message' => (string) ($payload['message'] ?? 'Command queued.'),
        ], $statusCode >= 200 && $statusCode < 300 ? $statusCode : JsonResponse::HTTP_ACCEPTED);
    }

    private function normalizeConsoleErrorCode(?string $legacy, int $statusCode): string
    {
        $code = strtoupper(trim((string) $legacy));
        if ($code === '') {
            return match ($statusCode) {
                401 => 'UNAUTHORIZED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                409 => 'INSTANCE_OFFLINE',
                429 => 'RATE_LIMITED',
                502, 503 => 'AGENT_UNREACHABLE',
                default => 'INTERNAL_ERROR',
            };
        }

        return match ($code) {
            'CONSOLE_RATE_LIMITED' => 'RATE_LIMITED',
            'CONSOLE_COMMAND_REQUIRED', 'CONSOLE_COMMAND_INVALID' => 'INVALID_INPUT',
            'INSTANCE_NOT_RUNNING' => 'INSTANCE_OFFLINE',
            'RATE_LIMITED' => 'RATE_LIMITED',
            'CONSOLE_UNAVAILABLE' => 'CONSOLE_UNAVAILABLE',
            'PERMISSION_DENIED' => 'FORBIDDEN',
            'INVALID_INPUT' => 'INVALID_INPUT',
            'FILES_FORBIDDEN' => 'FORBIDDEN',
            'FILES_UNAUTHORIZED' => 'UNAUTHORIZED',
            default => $code,
        };
    }


    /**
     * @return array{runtime_status: string, running: bool}
     */
    private function resolveConsoleRuntimeProbe(Instance $instance): array
    {
        $queryRuntime = strtolower((string) ($instance->getQueryStatusCache()['status'] ?? 'unknown'));

        try {
            $runtimePayload = $this->agentGameServerClient->getInstanceStatus($instance);
            $runtimeStatus = $this->normalizeAgentRuntimeStatus(
                $runtimePayload['status'] ?? null,
                $runtimePayload['running'] ?? null,
                $runtimePayload['online'] ?? null,
            );
            if ($runtimeStatus instanceof InstanceStatus) {
                return [
                    'runtime_status' => strtolower($runtimeStatus->value),
                    'running' => $runtimeStatus === InstanceStatus::Running,
                ];
            }
        } catch (\Throwable) {
            // Fall back to cached query runtime status when live probe is unavailable.
        }

        return [
            'runtime_status' => $queryRuntime,
            'running' => $queryRuntime === 'online' || $queryRuntime === 'running' || $queryRuntime === 'up',
        ];
    }

    private function normalizeAgentRuntimeStatus(mixed $status, mixed $running, mixed $online): ?InstanceStatus
    {
        if (is_string($status)) {
            return match (strtolower(trim($status))) {
                'running', 'online', 'up' => InstanceStatus::Running,
                'stopped', 'offline', 'down' => InstanceStatus::Stopped,
                'error', 'failed', 'crashed' => InstanceStatus::Error,
                default => null,
            };
        }

        if (is_bool($running)) {
            return $running ? InstanceStatus::Running : InstanceStatus::Stopped;
        }

        if (is_bool($online)) {
            return $online ? InstanceStatus::Running : InstanceStatus::Stopped;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function apiOk(Request $request, array $data, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'data' => $data,
            'request_id' => $this->resolveRequestId($request),
        ], $status);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function apiError(Request $request, string $errorCode, string $message, int $status, array $context = []): JsonResponse
    {
        $payload = [
            'ok' => false,
            'error_code' => $errorCode,
            'message' => $message,
            'request_id' => $this->resolveRequestId($request),
        ];

        if ($context !== []) {
            $payload['context'] = $context;
        }

        return new JsonResponse($payload, $status);
    }
}
