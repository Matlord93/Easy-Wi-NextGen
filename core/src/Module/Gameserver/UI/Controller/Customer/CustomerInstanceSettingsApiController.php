<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\BackupDefinition;
use App\Module\Core\Domain\Entity\BackupSchedule;
use App\Module\Core\Domain\Entity\ConfigSchema;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\Domain\Enum\InstanceScheduleAction;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\InstanceSlotService;
use App\Module\Gameserver\Application\MinecraftCatalogService;
use App\Module\Gameserver\Infrastructure\Repository\GameProfileRepository;
use App\Repository\BackupDefinitionRepository;
use App\Repository\ConfigSchemaRepository;
use App\Repository\GameDefinitionRepository;
use App\Repository\InstanceRepository;
use App\Repository\InstanceScheduleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use App\Module\Core\Attribute\RequiresModule;

#[RequiresModule('game')]
final class CustomerInstanceSettingsApiController
{
    private const int MAX_CONFIG_BYTES = 1_048_576;
    private const string DEFAULT_AUTO_BACKUP_TIME = '03:00';
    private const string DEFAULT_AUTO_RESTART_TIME = '04:00';
    private const string DEFAULT_AUTO_UPDATE_TIME = '05:00';

    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly GameDefinitionRepository $gameDefinitionRepository,
        private readonly ConfigSchemaRepository $configSchemaRepository,
        private readonly AppSettingsService $appSettingsService,
        private readonly BackupDefinitionRepository $backupDefinitionRepository,
        private readonly InstanceScheduleRepository $instanceScheduleRepository,
        private readonly MinecraftCatalogService $minecraftCatalogService,
        private readonly InstanceSlotService $instanceSlotService,
        private readonly GameProfileRepository $gameProfileRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/api/instances/{id}/settings', name: 'customer_instance_settings_api_summary', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/settings', name: 'customer_instance_settings_api_summary_v1', methods: ['GET'])]
    public function summary(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        $supportsSlots = $this->supportsSlots($instance);

        return $this->apiOk($request, [
            'instance_id' => $instance->getId(),
            'status' => strtolower($instance->getStatus()->value),
            'slots' => [
                'current_slots' => $instance->getSlots(),
                'max_slots' => $instance->getMaxSlots(),
                'lock_slots' => $instance->isLockSlots(),
            ],
            'supports_slots' => $supportsSlots,
            'configs' => $this->resolveSettingsConfigsForInstance($instance),
            'automation' => $this->buildAutomationPayload($customer, $instance),
            'capabilities' => $this->buildCapabilitiesPayload($supportsSlots),
        ]);
    }

    #[Route(path: '/api/instances/{id}/settings/automation', name: 'customer_instance_settings_api_automation_update', methods: ['PATCH'])]
    #[Route(path: '/api/v1/customer/instances/{id}/settings/automation', name: 'customer_instance_settings_api_automation_update_v1', methods: ['PATCH'])]
    public function updateAutomation(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
            $payload = $request->toArray();
        } catch (\JsonException) {
            return $this->apiError($request, 'INVALID_INPUT', 'Invalid JSON payload.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        if (!is_array($payload)) {
            return $this->apiError($request, 'INVALID_INPUT', 'Invalid payload.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $automation = is_array($payload['automation'] ?? null) ? $payload['automation'] : $payload;
        if (!is_array($automation) || $automation === []) {
            return $this->apiError($request, 'INVALID_INPUT', 'automation payload is required.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $backupTime = self::DEFAULT_AUTO_BACKUP_TIME;
        $restartTime = self::DEFAULT_AUTO_RESTART_TIME;
        $updateTime = self::DEFAULT_AUTO_UPDATE_TIME;

        if (is_array($automation['auto_backup'] ?? null)) {
            $backupInput = $automation['auto_backup'];
            $mode = strtolower(trim((string) ($backupInput['mode'] ?? 'manual')));
            if (!in_array($mode, ['auto', 'manual'], true)) {
                return $this->apiError($request, 'INVALID_INPUT', 'auto_backup.mode must be auto or manual.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            $enabled = (bool) ($backupInput['enabled'] ?? ($mode === 'auto'));
            $backupTimeInput = (string) ($backupInput['time'] ?? '');
            $validated = $this->validateTimeInput($backupTimeInput, self::DEFAULT_AUTO_BACKUP_TIME);
            if ($validated === null) {
                return $this->apiError($request, 'INVALID_INPUT', 'auto_backup.time must use HH:MM (24h).', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $backupTime = $validated;
        }

        if (is_array($automation['auto_restart'] ?? null)) {
            $restartInput = $automation['auto_restart'];
            $enabled = (bool) ($restartInput['enabled'] ?? false);
            $restartTimeInput = (string) ($restartInput['time'] ?? '');
            $validated = $this->validateTimeInput($restartTimeInput, self::DEFAULT_AUTO_RESTART_TIME);
            if ($validated === null) {
                return $this->apiError($request, 'INVALID_INPUT', 'auto_restart.time must use HH:MM (24h).', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $restartTime = $validated;
        }

        if (is_array($automation['auto_update'] ?? null)) {
            $updateInput = $automation['auto_update'];
            $enabled = (bool) ($updateInput['enabled'] ?? false);
            $updateTimeInput = (string) ($updateInput['time'] ?? '');
            $validated = $this->validateTimeInput($updateTimeInput, self::DEFAULT_AUTO_UPDATE_TIME);
            if ($validated === null) {
                return $this->apiError($request, 'INVALID_INPUT', 'auto_update.time must use HH:MM (24h).', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $updateTime = $validated;
        }

        if (is_array($automation['auto_update'] ?? null) && (bool) (($automation['auto_update']['enabled'] ?? false)) && $instance->getLockedVersion() !== null) {
            return $this->apiError($request, 'CONFLICT', 'Auto-update cannot be enabled while version lock is active.', JsonResponse::HTTP_CONFLICT);
        }

        $restartSchedule = $this->safeFindInstanceSchedule($instance, InstanceScheduleAction::Restart);
        $updateSchedule = $this->safeFindInstanceSchedule($instance, InstanceScheduleAction::Update);
        $definition = $this->ensureBackupDefinition($customer, $instance);
        $backupSchedule = $definition->getSchedule();

        if (is_array($automation['auto_backup'] ?? null)) {
            $backupInput = $automation['auto_backup'];
            $mode = strtolower(trim((string) ($backupInput['mode'] ?? 'manual')));

            $enabled = (bool) ($backupInput['enabled'] ?? ($mode === 'auto'));
            $scheduleEnabled = $mode === 'auto' && $enabled;
            if ($backupSchedule === null) {
                $backupSchedule = new BackupSchedule($definition, $this->timeToCron($backupTime), 30, 7, $scheduleEnabled);
                $backupSchedule->setTimeZone('UTC');
                $definition->setSchedule($backupSchedule);
                $this->entityManager->persist($backupSchedule);
            } else {
                $backupSchedule->update(
                    $this->timeToCron($backupTime),
                    $backupSchedule->getRetentionDays(),
                    $backupSchedule->getRetentionCount(),
                    $scheduleEnabled,
                    $backupSchedule->getTimeZone(),
                    $backupSchedule->getCompression(),
                    $backupSchedule->isStopBefore(),
                );
            }

            $setupVars = $instance->getSetupVars();
            $setupVars['EASYWI_BACKUP_MODE'] = $mode;
            $instance->setSetupVars($setupVars);
            $this->entityManager->persist($definition);
        }

        if (is_array($automation['auto_restart'] ?? null)) {
            $restartInput = $automation['auto_restart'];
            $enabled = (bool) ($restartInput['enabled'] ?? false);
            if ($restartSchedule === null) {
                $restartSchedule = new \App\Module\Core\Domain\Entity\InstanceSchedule(
                    $instance,
                    $customer,
                    InstanceScheduleAction::Restart,
                    $this->timeToCron($restartTime),
                    'UTC',
                    $enabled,
                );
                $this->entityManager->persist($restartSchedule);
            } else {
                $restartSchedule->update(
                    InstanceScheduleAction::Restart,
                    $this->timeToCron($restartTime),
                    $restartSchedule->getTimeZone(),
                    $enabled,
                );
            }
        }

        if (is_array($automation['version_lock'] ?? null)) {
            $lockInput = $automation['version_lock'];
            $enabled = (bool) ($lockInput['enabled'] ?? false);
            $versionRaw = trim((string) ($lockInput['version'] ?? ''));

            if ($enabled && $versionRaw === '') {
                return $this->apiError($request, 'INVALID_INPUT', 'version_lock.version is required when enabled.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            $availableVersions = $this->resolveAvailableVersions($instance);
            if ($enabled && $availableVersions !== [] && !in_array($versionRaw, $availableVersions, true)) {
                return $this->apiError($request, 'INVALID_INPUT', 'Selected lock version is not available.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($enabled) {
                $instance->setLockedVersion($versionRaw);
                if ($instance->getCurrentBuildId() !== null) {
                    $instance->setLockedBuildId($instance->getCurrentBuildId());
                }
            } else {
                $instance->setLockedVersion(null);
                $instance->setLockedBuildId(null);
            }
        }

        if (is_array($automation['auto_update'] ?? null)) {
            $updateInput = $automation['auto_update'];
            $enabled = (bool) ($updateInput['enabled'] ?? false);

            $instance->setUpdatePolicy($enabled ? InstanceUpdatePolicy::Auto : InstanceUpdatePolicy::Manual);
            if ($updateSchedule === null) {
                $updateSchedule = new \App\Module\Core\Domain\Entity\InstanceSchedule(
                    $instance,
                    $customer,
                    InstanceScheduleAction::Update,
                    $this->timeToCron($updateTime),
                    'UTC',
                    $enabled,
                );
                $this->entityManager->persist($updateSchedule);
            } else {
                $updateSchedule->update(
                    InstanceScheduleAction::Update,
                    $this->timeToCron($updateTime),
                    $updateSchedule->getTimeZone(),
                    $enabled,
                );
            }
        }

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        $supportsSlots = $this->supportsSlots($instance);

        return $this->apiOk($request, [
            'instance_id' => $instance->getId(),
            'automation' => $this->buildAutomationPayload($customer, $instance),
            'capabilities' => $this->buildCapabilitiesPayload($supportsSlots),
        ]);
    }

    #[Route(path: '/api/instances/{id}/settings/health', name: 'customer_instance_settings_api_health', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/settings/health', name: 'customer_instance_settings_api_health_v1', methods: ['GET'])]
    public function health(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        $supportsSlots = $this->supportsSlots($instance);

        return $this->apiOk($request, [
            'instance_id' => $instance->getId(),
            'settings_supported' => true,
            'supports_auto_backup' => true,
            'supports_auto_restart' => true,
            'supports_auto_update' => true,
            'supports_version_lock' => true,
            'supports_reinstall' => true,
            'supports_backup_download' => true,
        ]);
    }

    private function ensureBackupDefinition(User $customer, Instance $instance): BackupDefinition
    {
        try {
            $definitions = $this->backupDefinitionRepository->findByCustomer($customer);
        } catch (\Throwable) {
            $definitions = [];
        }
        foreach ($definitions as $definition) {
            if ($definition->getTargetType() !== BackupTargetType::Game) {
                continue;
            }
            if ($definition->getTargetId() !== (string) $instance->getId()) {
                continue;
            }

            return $definition;
        }

        $definition = new BackupDefinition($customer, BackupTargetType::Game, (string) ($instance->getId() ?? ''), null);
        $this->entityManager->persist($definition);

        return $definition;
    }

    private function findBackupDefinition(User $customer, Instance $instance): ?BackupDefinition
    {
        try {
            $definitions = $this->backupDefinitionRepository->findByCustomer($customer);
        } catch (\Throwable) {
            return null;
        }

        foreach ($definitions as $definition) {
            if ($definition->getTargetType() !== BackupTargetType::Game) {
                continue;
            }
            if ($definition->getTargetId() !== (string) $instance->getId()) {
                continue;
            }

            return $definition;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAutomationPayload(User $customer, Instance $instance): array
    {
        $restartSchedule = $this->safeFindInstanceSchedule($instance, InstanceScheduleAction::Restart);
        $updateSchedule = $this->safeFindInstanceSchedule($instance, InstanceScheduleAction::Update);
        $backupDefinition = $this->findBackupDefinition($customer, $instance);
        $backupSchedule = $backupDefinition?->getSchedule();
        $setupVars = $instance->getSetupVars();
        $backupMode = strtolower(trim((string) ($setupVars['EASYWI_BACKUP_MODE'] ?? ($backupSchedule?->isEnabled() ? 'auto' : 'manual'))));
        if (!in_array($backupMode, ['auto', 'manual'], true)) {
            $backupMode = 'manual';
        }
        $availableVersions = $this->resolveAvailableVersions($instance);

        $backupTime = $this->cronToTime($backupSchedule?->getCronExpression(), self::DEFAULT_AUTO_BACKUP_TIME);
        $restartTime = $this->cronToTime($restartSchedule?->getCronExpression(), self::DEFAULT_AUTO_RESTART_TIME);
        $updateTime = $this->cronToTime($updateSchedule?->getCronExpression(), self::DEFAULT_AUTO_UPDATE_TIME);

        return [
            'auto_backup' => [
                'enabled' => $backupSchedule?->isEnabled() ?? false,
                'mode' => $backupMode,
                'time' => $backupTime,
                'schedule' => $backupSchedule === null ? null : [
                    'cron_expression' => $backupSchedule->getCronExpression(),
                    'time_zone' => $backupSchedule->getTimeZone(),
                ],
            ],
            'auto_restart' => [
                'enabled' => $restartSchedule?->isEnabled() ?? false,
                'policy' => 'cron',
                'time' => $restartTime,
            ],
            'auto_update' => [
                'enabled' => $instance->getUpdatePolicy() === InstanceUpdatePolicy::Auto,
                'channel' => 'stable',
                'time' => $updateTime,
            ],
            'version_lock' => [
                'enabled' => $instance->getLockedVersion() !== null,
                'version' => $instance->getLockedVersion(),
                'available_versions' => $availableVersions,
            ],
        ];
    }

    /** @return string[] */
    private function resolveAvailableVersions(Instance $instance): array
    {
        $resolver = $instance->getTemplate()->getInstallResolver();
        $type = is_array($resolver) ? (string) ($resolver['type'] ?? '') : '';

        if ($type === 'minecraft_vanilla') {
            return $this->minecraftCatalogService->getUiCatalog()['vanilla']['versions'] ?? [];
        }

        if ($type === 'papermc_paper') {
            return $this->minecraftCatalogService->getUiCatalog()['paper']['versions'] ?? [];
        }

        $versions = array_values(array_filter([
            $instance->getCurrentVersion(),
            $instance->getLockedVersion(),
            $instance->getPreviousVersion(),
        ], static fn (?string $value): bool => is_string($value) && trim($value) !== ''));

        return array_values(array_unique($versions));
    }

    #[Route(path: '/api/instances/{id}/configs', name: 'customer_instance_configs_envelope_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs', name: 'customer_instance_configs_envelope_list_v1', methods: ['GET'])]
    public function listConfigs(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        return $this->apiOk($request, ['configs' => $this->resolveSettingsConfigsForInstance($instance)]);
    }

    #[Route(path: '/api/instances/{id}/configs/{configId}', name: 'customer_instance_configs_envelope_show', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs/{configId}', name: 'customer_instance_configs_envelope_show_v1', methods: ['GET'])]
    public function showConfig(Request $request, int $id, string $configId): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
            $config = $this->resolveConfigForInstance($instance, $configId);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        return $this->apiOk($request, $config);
    }

    #[Route(path: '/api/instances/{id}/configs', name: 'customer_instance_configs_envelope_create', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs', name: 'customer_instance_configs_envelope_create_v1', methods: ['POST'])]
    public function createConfig(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
            $payload = $request->toArray();
        } catch (\JsonException) {
            return $this->apiError($request, 'INVALID_INPUT', 'Invalid JSON payload.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->apiError($request, 'INVALID_INPUT', 'name is required.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $baseConfigId = trim((string) ($payload['base_config_id'] ?? ''));
        $format = strtolower(trim((string) ($payload['format'] ?? 'txt')));
        $initialContent = (string) ($payload['initial_content'] ?? '');

        $base = null;
        if ($baseConfigId !== '') {
            try {
                $base = $this->resolveConfigForInstance($instance, $baseConfigId);
            } catch (HttpExceptionInterface $exception) {
                return $this->mapException($request, $exception);
            }

            if ($initialContent === '') {
                $initialContent = (string) ($base['content'] ?? '');
            }
            if ($format === '' || $format === 'txt') {
                $format = (string) ($base['format'] ?? 'txt');
            }
        }

        if (!$this->isSupportedFormat($format)) {
            return $this->apiError($request, 'INVALID_INPUT', 'Unsupported format.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (strlen($initialContent) > self::MAX_CONFIG_BYTES) {
            return $this->apiError($request, 'INVALID_INPUT', 'Config content exceeds maximum size.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $filePath = $this->buildInstanceConfigPath($name, $format);
        if ($this->configPathExists($instance, $filePath)) {
            return $this->apiError($request, 'CONFLICT', 'Config name already exists.', JsonResponse::HTTP_CONFLICT);
        }

        $this->storeInstanceOverride($instance, $filePath, $initialContent, [
            'name' => $name,
            'format' => $format,
            'source' => 'instance',
            'scope' => 'instance',
            'editable' => true,
        ]);
        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        return $this->apiOk($request, [
            'created' => $this->resolveConfigForInstance($instance, $this->instanceConfigIdForPath($filePath)),
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/instances/{id}/configs/{configId}', name: 'customer_instance_configs_envelope_update', methods: ['PUT'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs/{configId}', name: 'customer_instance_configs_envelope_update_v1', methods: ['PUT'])]
    public function updateConfig(Request $request, int $id, string $configId): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
            $payload = $request->toArray();
        } catch (\JsonException) {
            return $this->apiError($request, 'INVALID_INPUT', 'Invalid JSON payload.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        if (!array_key_exists('content', $payload)) {
            return $this->apiError($request, 'INVALID_INPUT', 'content is required.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $config = $this->resolveConfigForInstance($instance, $configId);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        if (($config['editable'] ?? false) !== true) {
            return $this->apiError($request, 'FORBIDDEN', 'Config is not editable.', JsonResponse::HTTP_FORBIDDEN);
        }

        $content = (string) $payload['content'];
        if (strlen($content) > self::MAX_CONFIG_BYTES) {
            return $this->apiError($request, 'INVALID_INPUT', 'Config content exceeds maximum size.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->storeInstanceOverride($instance, (string) $config['file_path'], $content, [
            'name' => (string) $config['name'],
            'format' => (string) $config['format'],
            'source' => 'instance',
            'scope' => (string) $config['scope'],
            'editable' => (bool) $config['editable'],
        ]);
        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        $updated = $this->resolveConfigForInstance($instance, $configId);

        return $this->apiOk($request, [
            'updated' => true,
            'id' => $updated['id'],
            'etag' => $updated['etag'],
            'size' => $updated['size'],
        ], JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/instances/{id}/configs/{configId}/apply', name: 'customer_instance_configs_envelope_apply', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs/{configId}/apply', name: 'customer_instance_configs_envelope_apply_v1', methods: ['POST'])]
    public function applyConfig(Request $request, int $id, string $configId): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
            $config = $this->resolveConfigForInstance($instance, $configId);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        $job = new Job('instance.config.apply', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'config_id' => (string) $config['id'],
            'config_key' => (string) ($config['config_key'] ?? $config['id']),
            'file_path' => (string) $config['file_path'],
        ]);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->apiOk($request, [
            'job_id' => $job->getId(),
            'job_type' => 'instance.config.apply',
            'status' => 'queued',
            'config_id' => (string) $config['id'],
        ], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveSettingsConfigsForInstance(Instance $instance): array
    {
        try {
            $template = $instance->getTemplate();
            $gameDefinition = $this->gameDefinitionRepository->findOneBy(['gameKey' => $template->getGameKey()]);
            $schemas = $gameDefinition === null ? [] : $this->configSchemaRepository->findByGameDefinition($gameDefinition);
        } catch (\Throwable) {
            $schemas = [];
        }
        $overrides = $instance->getConfigOverrides();

        $configs = [];
        $knownPaths = [];

        foreach ($schemas as $schema) {
            if (!$schema instanceof ConfigSchema) {
                continue;
            }
            $filePath = $schema->getFilePath();
            $knownPaths[$filePath] = true;
            $override = $overrides[$filePath] ?? null;
            $exists = is_array($override);
            $updatedAt = $exists ? (string) ($override['last_updated_at'] ?? $override['updated_at'] ?? '') : $schema->getUpdatedAt()->format(DATE_ATOM);

            $configs[] = [
                'id' => (string) $schema->getId(),
                'name' => $schema->getName(),
                'config_key' => $schema->getConfigKey(),
                'file_path' => $filePath,
                'scope' => 'template',
                'format' => $schema->getFormat(),
                'editable' => true,
                'exists' => $exists,
                'source' => $exists ? 'instance' : 'template',
                'updated_at' => $updatedAt,
            ];
        }

        foreach ($overrides as $filePath => $override) {
            if (!is_string($filePath) || isset($knownPaths[$filePath]) || !is_array($override)) {
                continue;
            }

            $format = is_string($override['format'] ?? null) && $override['format'] !== ''
                ? (string) $override['format']
                : $this->inferFormatFromPath($filePath);
            $name = is_string($override['name'] ?? null) && trim((string) $override['name']) !== ''
                ? trim((string) $override['name'])
                : basename($filePath);

            $configs[] = [
                'id' => $this->instanceConfigIdForPath($filePath),
                'name' => $name,
                'config_key' => $filePath,
                'file_path' => $filePath,
                'scope' => 'instance',
                'format' => $format,
                'editable' => true,
                'exists' => true,
                'source' => 'instance',
                'updated_at' => (string) ($override['last_updated_at'] ?? $override['updated_at'] ?? ''),
            ];
        }

        usort($configs, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return $configs;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveConfigForInstance(Instance $instance, string $configId): array
    {
        $configs = $this->resolveSettingsConfigsForInstance($instance);
        foreach ($configs as $config) {
            if ((string) ($config['id'] ?? '') !== $configId) {
                continue;
            }

            $overrides = $instance->getConfigOverrides();
            $filePath = (string) $config['file_path'];
            $override = $overrides[$filePath] ?? null;
            $content = is_array($override) ? (string) ($override['content'] ?? '') : '';
            $etag = hash('sha256', $content);

            return [
                'id' => $config['id'],
                'name' => $config['name'],
                'scope' => $config['scope'],
                'format' => $config['format'],
                'editable' => (bool) $config['editable'],
                'exists' => (bool) $config['exists'],
                'source' => $config['source'],
                'file_path' => $filePath,
                'config_key' => $config['config_key'] ?? $filePath,
                'content' => $content,
                'etag' => $etag,
                'size' => strlen($content),
                'updated_at' => $config['updated_at'] ?? '',
            ];
        }

        throw new NotFoundHttpException('Config not found for this instance.');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function storeInstanceOverride(Instance $instance, string $filePath, string $content, array $metadata): void
    {
        $instance->setConfigOverride($filePath, $content);
        $overrides = $instance->getConfigOverrides();
        if (!isset($overrides[$filePath]) || !is_array($overrides[$filePath])) {
            return;
        }

        foreach ($metadata as $key => $value) {
            $overrides[$filePath][$key] = $value;
        }

        $instance->setConfigOverrides($overrides);
    }

    private function configPathExists(Instance $instance, string $filePath): bool
    {
        foreach ($this->resolveSettingsConfigsForInstance($instance) as $config) {
            if (($config['file_path'] ?? null) === $filePath) {
                return true;
            }
        }

        return false;
    }

    private function buildInstanceConfigPath(string $name, string $format): string
    {
        $base = strtolower(trim($name));
        $base = preg_replace('/[^a-z0-9._-]+/i', '-', $base) ?? '';
        $base = trim($base, '-.');
        if ($base === '') {
            throw new BadRequestHttpException('Invalid config name.');
        }

        $extension = strtolower(trim($format));
        $hasExtension = str_contains($base, '.');
        $filename = $hasExtension ? $base : sprintf('%s.%s', $base, $extension);

        return 'custom/' . $filename;
    }

    private function inferFormatFromPath(string $filePath): string
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        return $this->isSupportedFormat($extension) ? $extension : 'txt';
    }

    private function isSupportedFormat(string $format): bool
    {
        return in_array(strtolower($format), ['txt', 'cfg', 'ini', 'json', 'yaml', 'yml', 'xml', 'properties', 'conf', 'env', 'log'], true);
    }

    private function instanceConfigIdForPath(string $filePath): string
    {
        return 'instance:' . rawurlencode($filePath);
    }



    private function supportsSlots(Instance $instance): bool
    {
        try {
            return $this->gameProfileRepository->findOneByGameKey($instance->getTemplate()->getGameKey()) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function safeFindInstanceSchedule(Instance $instance, InstanceScheduleAction $action): ?\App\Module\Core\Domain\Entity\InstanceSchedule
    {
        try {
            return $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, $action);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, bool>
     */
    private function buildCapabilitiesPayload(bool $supportsSlots): array
    {
        return [
            'supports_auto_backup' => true,
            'supports_auto_restart' => true,
            'supports_auto_update' => true,
            'supports_version_lock' => true,
            'supports_slots' => $supportsSlots,
        ];
    }

    private function validateTimeInput(string $time, string $fallback): ?string
    {
        $value = trim($time);
        if ($value === '') {
            return $fallback;
        }

        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            return null;
        }

        return $value;
    }

    private function timeToCron(string $time): string
    {
        [$hour, $minute] = explode(':', $time, 2);

        return sprintf('%d %d * * *', (int) $minute, (int) $hour);
    }

    private function cronToTime(?string $cron, string $fallback): string
    {
        $expression = trim((string) $cron);
        if ($expression === '') {
            return $fallback;
        }

        $parts = preg_split('/\s+/', $expression) ?: [];
        if (count($parts) < 2) {
            return $fallback;
        }

        $minute = $parts[0] ?? '';
        $hour = $parts[1] ?? '';
        if (!ctype_digit($minute) || !ctype_digit($hour)) {
            return $fallback;
        }

        $minuteInt = (int) $minute;
        $hourInt = (int) $hour;
        if ($minuteInt < 0 || $minuteInt > 59 || $hourInt < 0 || $hourInt > 23) {
            return $fallback;
        }

        return sprintf('%02d:%02d', $hourInt, $minuteInt);
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

    private function mapException(Request $request, HttpExceptionInterface $exception): JsonResponse
    {
        return $this->apiError(
            $request,
            $exception instanceof BadRequestHttpException
                ? 'INVALID_INPUT'
                : ($exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND')),
            $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
            $exception->getStatusCode(),
        );
    }

    private function apiOk(Request $request, array $data, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'data' => $data,
            'request_id' => $this->resolveRequestId($request),
        ], $status);
    }

    private function apiError(Request $request, string $errorCode, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'error_code' => $errorCode,
            'message' => $message,
            'request_id' => $this->resolveRequestId($request),
        ], $status);
    }

    private function resolveRequestId(Request $request): string
    {
        return trim((string) ($request->headers->get('X-Request-ID') ?: $request->attributes->get('request_id') ?: ''));
    }
}
