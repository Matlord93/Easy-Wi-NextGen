<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Message\InstanceActionMessage;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Application\SetupChecker;
use App\Module\Core\Domain\Entity\BackupDefinition;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\Domain\Enum\InstanceScheduleAction;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Gameserver\Application\ConsoleCommandValidator;
use App\Module\Gameserver\Application\GameServerPathResolver;
use App\Module\Gameserver\Application\InstanceJobPayloadBuilder;
use App\Module\Gameserver\Application\TemplateInstallResolver;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Repository\BackupDefinitionRepository;
use App\Repository\BackupRepository;
use App\Repository\GamePluginRepository;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use Cron\CronExpression;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerInstanceActionApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly BackupDefinitionRepository $backupDefinitionRepository,
        private readonly BackupRepository $backupRepository,
        private readonly GamePluginRepository $gamePluginRepository,
        private readonly PortBlockRepository $portBlockRepository,
        private readonly JobRepository $jobRepository,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly AuditLogger $auditLogger,
        private readonly ConsoleCommandValidator $consoleCommandValidator,
        private readonly GameServerPathResolver $gameServerPathResolver,
        private readonly SetupChecker $setupChecker,
        private readonly TemplateInstallResolver $templateInstallResolver,
        private readonly InstanceJobPayloadBuilder $instanceJobPayloadBuilder,
        #[Autowire(service: 'limiter.instance_console_commands')]
        private readonly RateLimiterFactory $consoleLimiter,
        private readonly \Doctrine\ORM\EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
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

    #[Route(path: '/api/instances/{id}/backups', name: 'customer_instance_backups_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/backups', name: 'customer_instance_backups_list_v1', methods: ['GET'])]
    public function listBackups(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

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

        return new JsonResponse([
            'backups' => array_map(fn ($backup) => $this->normalizeBackup($backup), $backups),
        ]);
    }

    #[Route(path: '/api/instances/{id}/backups', name: 'customer_instance_backups_create', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/backups', name: 'customer_instance_backups_create_v1', methods: ['POST'])]
    public function createBackup(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $payload = $this->parsePayload($request);

        $definition = $this->resolveDefinition($customer, $instance, $payload);
        if ($definition === null) {
            return $this->responseEnvelopeFactory->error(
                $request,
                'Backup definition not found.',
                'backup_definition_not_found',
                JsonResponse::HTTP_NOT_FOUND,
            );
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return $this->responseEnvelopeFactory->error(
                $request,
                $blockMessage,
                'disk_quota_exceeded',
                JsonResponse::HTTP_CONFLICT,
            );
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
            return $this->responseEnvelopeFactory->error(
                $request,
                'Unable to queue backup creation job.',
                'backup_queue_failed',
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return $this->responseEnvelopeFactory->success(
            $request,
            $result['job_id'],
            'Backup creation queued.',
            JsonResponse::HTTP_ACCEPTED,
            [
                'backup_id' => $result['backup_id'] ?? null,
            ],
        );
    }

    #[Route(path: '/api/instances/{id}/backups/{backupId}/restore', name: 'customer_instance_backups_restore', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/backups/{backupId}/restore', name: 'customer_instance_backups_restore_v1', methods: ['POST'])]
    public function restoreBackup(Request $request, int $id, int $backupId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $payload = $this->parsePayload($request);

        $backup = $this->backupRepository->find($backupId);
        if ($backup === null || $backup->getDefinition()->getTargetType() !== BackupTargetType::Game || $backup->getDefinition()->getTargetId() !== (string) $instance->getId()) {
            return $this->responseEnvelopeFactory->error(
                $request,
                'Backup not found.',
                'backup_not_found',
                JsonResponse::HTTP_NOT_FOUND,
            );
        }

        if (!(bool) ($payload['confirm'] ?? false)) {
            return $this->responseEnvelopeFactory->error(
                $request,
                'Restore confirmation is required.',
                'backup_restore_confirm_required',
                JsonResponse::HTTP_BAD_REQUEST,
            );
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return $this->responseEnvelopeFactory->error(
                $request,
                $blockMessage,
                'disk_quota_exceeded',
                JsonResponse::HTTP_CONFLICT,
            );
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
            return $this->responseEnvelopeFactory->error(
                $request,
                'Unable to queue backup restore job.',
                'backup_restore_queue_failed',
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return $this->responseEnvelopeFactory->success(
            $request,
            $result['job_id'],
            'Backup restore queued.',
            JsonResponse::HTTP_ACCEPTED,
            [
                'backup_id' => $backup->getId(),
                'pre_backup_job_id' => $result['pre_backup_job_id'] ?? null,
            ],
        );
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

        $runtimeStatus = strtolower((string) ($instance->getQueryStatusCache()['status'] ?? ''));
        if ($instance->getStatus() !== InstanceStatus::Running && $runtimeStatus !== 'online') {
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

    #[Route(path: '/api/instances/{id}/console/logs', name: 'customer_instance_console_logs', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/console/logs', name: 'customer_instance_console_logs_v1', methods: ['POST'])]
    public function requestConsoleLogs(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $job = null;

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
        }

        if ($job === null) {
            $job = $this->findLatestConsoleJob($instance);
        }

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
            return new JsonResponse(['error' => 'Confirmation is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $status = $this->setupChecker->getSetupStatus($instance);
        if (!$status['is_ready'] && in_array(SetupChecker::ACTION_INSTALL, $status['blocked_actions'], true)) {
            return new JsonResponse([
                'error' => 'Setup requirements missing.',
                'error_code' => 'MISSING_REQUIREMENTS',
                'missing' => $status['missing'],
            ], JsonResponse::HTTP_CONFLICT);
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return new JsonResponse(['error' => $blockMessage], JsonResponse::HTTP_BAD_REQUEST);
        }

        $payload = $this->instanceJobPayloadBuilder->buildSniperInstallPayload($instance);
        $payload['autostart'] = 'false';

        $message = new InstanceActionMessage('instance.reinstall', $customer->getId(), $instance->getId(), $payload);

        return $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
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
        if ($plugin === null || $plugin->getTemplate()->getId() !== $instance->getTemplate()->getId()) {
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

        $message = new InstanceActionMessage(sprintf('instance.addon.%s', $action), $customer->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'plugin_id' => (string) $plugin->getId(),
            'plugin_name' => $plugin->getName(),
            'plugin_version' => $plugin->getVersion(),
            'plugin_checksum' => $plugin->getChecksum(),
            'plugin_download_url' => $plugin->getDownloadUrl(),
        ]);

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
}
