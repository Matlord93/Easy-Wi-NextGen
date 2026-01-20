<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\BackupDefinition;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\Domain\Enum\InstanceScheduleAction;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Message\InstanceActionMessage;
use App\Repository\BackupDefinitionRepository;
use App\Repository\BackupRepository;
use App\Repository\GamePluginRepository;
use App\Repository\InstanceRepository;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Gameserver\Application\TemplateInstallResolver;
use App\Module\Core\Application\SetupChecker;
use Cron\CronExpression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerInstanceActionApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly BackupDefinitionRepository $backupDefinitionRepository,
        private readonly BackupRepository $backupRepository,
        private readonly GamePluginRepository $gamePluginRepository,
        private readonly PortBlockRepository $portBlockRepository,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly SetupChecker $setupChecker,
        private readonly TemplateInstallResolver $templateInstallResolver,
        private readonly \Doctrine\ORM\EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/api/instances/{id}/addons/install', name: 'customer_instance_addons_install', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/addons/install', name: 'customer_instance_addons_install_v1', methods: ['POST'])]
    public function installAddon(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $payload = $this->parsePayload($request);

        $pluginId = $payload['plugin_id'] ?? null;
        if (!is_int($pluginId) && !is_string($pluginId)) {
            return new JsonResponse(['error' => 'plugin_id is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $plugin = $this->gamePluginRepository->find((int) $pluginId);
        if ($plugin === null || $plugin->getTemplate()->getId() !== $instance->getTemplate()->getId()) {
            return new JsonResponse(['error' => 'Plugin not found for this instance.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return new JsonResponse(['error' => $blockMessage], JsonResponse::HTTP_BAD_REQUEST);
        }

        $message = new InstanceActionMessage('instance.addon.install', $customer->getId(), $instance->getId(), [
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

        return $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/instances/{id}/addons/remove', name: 'customer_instance_addons_remove', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/addons/remove', name: 'customer_instance_addons_remove_v1', methods: ['POST'])]
    public function removeAddon(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $payload = $this->parsePayload($request);

        $pluginId = $payload['plugin_id'] ?? null;
        if (!is_int($pluginId) && !is_string($pluginId)) {
            return new JsonResponse(['error' => 'plugin_id is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $plugin = $this->gamePluginRepository->find((int) $pluginId);
        if ($plugin === null || $plugin->getTemplate()->getId() !== $instance->getTemplate()->getId()) {
            return new JsonResponse(['error' => 'Plugin not found for this instance.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return new JsonResponse(['error' => $blockMessage], JsonResponse::HTTP_BAD_REQUEST);
        }

        $message = new InstanceActionMessage('instance.addon.remove', $customer->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'plugin_id' => (string) $plugin->getId(),
            'plugin_name' => $plugin->getName(),
        ]);

        return $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/instances/{id}/addons/update', name: 'customer_instance_addons_update', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/addons/update', name: 'customer_instance_addons_update_v1', methods: ['POST'])]
    public function updateAddon(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $payload = $this->parsePayload($request);

        $pluginId = $payload['plugin_id'] ?? null;
        if (!is_int($pluginId) && !is_string($pluginId)) {
            return new JsonResponse(['error' => 'plugin_id is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $plugin = $this->gamePluginRepository->find((int) $pluginId);
        if ($plugin === null || $plugin->getTemplate()->getId() !== $instance->getTemplate()->getId()) {
            return new JsonResponse(['error' => 'Plugin not found for this instance.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return new JsonResponse(['error' => $blockMessage], JsonResponse::HTTP_BAD_REQUEST);
        }

        $message = new InstanceActionMessage('instance.addon.update', $customer->getId(), $instance->getId(), [
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

        return $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
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
            return new JsonResponse(['error' => 'Backup definition not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return new JsonResponse(['error' => $blockMessage], JsonResponse::HTTP_BAD_REQUEST);
        }

        $message = new InstanceActionMessage('instance.backup.create', $customer->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'definition_id' => $definition->getId(),
        ]);

        return $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/instances/{id}/backups/{backupId}/restore', name: 'customer_instance_backups_restore', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/backups/{backupId}/restore', name: 'customer_instance_backups_restore_v1', methods: ['POST'])]
    public function restoreBackup(Request $request, int $id, int $backupId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        $backup = $this->backupRepository->find($backupId);
        if ($backup === null || $backup->getDefinition()->getTargetType() !== BackupTargetType::Game || $backup->getDefinition()->getTargetId() !== (string) $instance->getId()) {
            return new JsonResponse(['error' => 'Backup not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return new JsonResponse(['error' => $blockMessage], JsonResponse::HTTP_BAD_REQUEST);
        }

        $message = new InstanceActionMessage('instance.backup.restore', $customer->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'backup_id' => $backup->getId(),
        ]);

        return $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
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
            return new JsonResponse(['error' => 'Invalid schedule action.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $cronExpression = trim((string) ($payload['cron_expression'] ?? ''));
        $timeZone = trim((string) ($payload['time_zone'] ?? 'UTC'));
        $enabled = (bool) ($payload['enabled'] ?? true);

        if ($enabled && $cronExpression === '') {
            return new JsonResponse(['error' => 'Cron expression is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($enabled && !CronExpression::isValidExpression($cronExpression)) {
            return new JsonResponse(['error' => 'Cron expression is invalid.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $timeZone = $timeZone === '' ? 'UTC' : $timeZone;
        try {
            new \DateTimeZone($timeZone);
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Time zone is invalid.'], JsonResponse::HTTP_BAD_REQUEST);
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

        if ($command === '') {
            return new JsonResponse(['error' => 'Command is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($instance->getStatus() !== InstanceStatus::Running) {
            return new JsonResponse(['error' => 'Instance must be running to send commands.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return new JsonResponse(['error' => $blockMessage], JsonResponse::HTTP_BAD_REQUEST);
        }

        $message = new InstanceActionMessage('instance.console.command', $customer->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'command' => $command,
        ]);

        return $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/instances/{id}/console/logs', name: 'customer_instance_console_logs', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/console/logs', name: 'customer_instance_console_logs_v1', methods: ['POST'])]
    public function requestConsoleLogs(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $payload = $this->parsePayload($request);
        $linesValue = $payload['lines'] ?? 200;

        if (!is_numeric($linesValue)) {
            return new JsonResponse(['error' => 'lines must be numeric.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $lines = max(1, min(2000, (int) $linesValue));

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return new JsonResponse(['error' => $blockMessage], JsonResponse::HTTP_BAD_REQUEST);
        }

        $message = new InstanceActionMessage('instance.console.logs', $customer->getId(), $instance->getId(), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'lines' => (string) $lines,
        ]);

        return $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
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
            return new JsonResponse(['error' => 'Invalid update policy.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $cronExpression = trim((string) ($payload['cron_expression'] ?? ''));
        $timeZone = trim((string) ($payload['time_zone'] ?? 'UTC'));
        if ($policy === InstanceUpdatePolicy::Auto && $cronExpression === '') {
            return new JsonResponse(['error' => 'Auto updates require a cron schedule.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($policy === InstanceUpdatePolicy::Auto && !CronExpression::isValidExpression($cronExpression)) {
            return new JsonResponse(['error' => 'Cron expression is invalid.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $timeZone = $timeZone === '' ? 'UTC' : $timeZone;
        try {
            new \DateTimeZone($timeZone);
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Time zone is invalid.'], JsonResponse::HTTP_BAD_REQUEST);
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

        $portBlock = $this->portBlockRepository->findByInstance($instance);
        $message = new InstanceActionMessage('instance.reinstall', $customer->getId(), $instance->getId(), [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'cpu_limit' => (string) $instance->getCpuLimit(),
            'ram_limit' => (string) $instance->getRamLimit(),
            'disk_limit' => (string) $instance->getDiskLimit(),
            'start_params' => $instance->getTemplate()->getStartParams(),
            'required_ports' => implode(',', $instance->getTemplate()->getRequiredPortLabels()),
            'port_block_ports' => $portBlock ? implode(',', array_map('strval', $portBlock->getPorts())) : '',
            'install_command' => $this->templateInstallResolver->resolveInstallCommand($instance),
        ]);

        return $this->dispatchJob($message, JsonResponse::HTTP_ACCEPTED);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
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

        if ($instance->getCustomer()->getId() !== $customer->getId()) {
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
}
