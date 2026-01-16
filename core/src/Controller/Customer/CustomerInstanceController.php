<?php

declare(strict_types=1);

namespace App\Controller\Customer;

use App\Entity\Instance;
use App\Entity\InstanceSchedule;
use App\Entity\Job;
use App\Entity\User;
use App\Enum\InstanceScheduleAction;
use App\Enum\InstanceUpdatePolicy;
use App\Enum\InstanceStatus;
use App\Enum\BackupTargetType;
use App\Enum\UserType;
use App\Repository\BackupDefinitionRepository;
use App\Repository\InstanceRepository;
use App\Repository\InstanceScheduleRepository;
use App\Repository\JobRepository;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Service\AuditLogger;
use App\Service\DiskEnforcementService;
use App\Service\DiskUsageFormatter;
use App\Service\InstanceJobPayloadBuilder;
use App\Service\MinecraftCatalogService;
use App\Service\SetupChecker;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/instances')]
final class CustomerInstanceController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceScheduleRepository $instanceScheduleRepository,
        private readonly PortBlockRepository $portBlockRepository,
        private readonly BackupDefinitionRepository $backupDefinitionRepository,
        private readonly JobRepository $jobRepository,
        private readonly InstanceJobPayloadBuilder $instanceJobPayloadBuilder,
        private readonly AuditLogger $auditLogger,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly DiskUsageFormatter $diskUsageFormatter,
        private readonly MinecraftCatalogService $minecraftCatalogService,
        private readonly SetupChecker $setupChecker,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_instances', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $instances = $this->instanceRepository->findByCustomer($customer);

        return new Response($this->twig->render('customer/instances/index.html.twig', [
            'instances' => $this->normalizeInstances($instances),
            'activeNav' => 'instances',
            'minecraftCatalog' => $this->minecraftCatalogService->getUiCatalog(),
        ]));
    }

    #[Route(path: '/{id}', name: 'customer_instance_detail', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $activeTab = $this->resolveTab((string) $request->query->get('tab', 'overview'));

        $updateSchedule = $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, InstanceScheduleAction::Update);
        $portBlock = $this->portBlockRepository->findByInstance($instance);
        $instanceView = $this->normalizeInstance($instance, $updateSchedule, $portBlock);

        $configFiles = $this->normalizeConfigFiles($instance->getTemplate()->getConfigFiles());
        $restartSchedule = $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, InstanceScheduleAction::Restart);
        $restartScheduleView = $restartSchedule === null ? null : [
            'cron_expression' => $restartSchedule->getCronExpression(),
            'time_zone' => $restartSchedule->getTimeZone() ?? 'UTC',
            'enabled' => $restartSchedule->isEnabled(),
        ];

        $tabs = $this->buildTabs($instance->getId());

        return new Response($this->twig->render('customer/instances/show.html.twig', [
            'instance' => $instanceView,
            'template' => [
                'start_params' => $instance->getTemplate()->getStartParams(),
                'env_vars' => $instance->getTemplate()->getEnvVars(),
            ],
            'configFiles' => $configFiles,
            'pluginPaths' => $instance->getTemplate()->getPluginPaths(),
            'fastdl' => $this->normalizeFastdlSettings($instance->getTemplate()->getFastdlSettings()),
            'restartSchedule' => $restartScheduleView,
            'backups' => $this->normalizeBackupDefinitions($customer, $instance),
            'jobs' => $this->normalizeJobsForInstance($instance),
            'activeNav' => 'instances',
            'tabs' => $tabs,
            'activeTab' => $activeTab,
            'tabTemplate' => sprintf('customer/instances/tabs/%s.html.twig', $activeTab),
            'tabNotice' => $this->resolveNoticeKey((string) $request->query->get('notice', '')),
            'tabError' => $this->resolveErrorKey((string) $request->query->get('error', '')),
            'minecraftCatalog' => $this->minecraftCatalogService->getUiCatalog(),
        ]));
    }

    #[Route(path: '/{id}/restart-planner', name: 'customer_instance_restart_planner', methods: ['POST'])]
    public function updateRestartPlanner(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        $cronExpression = trim((string) $request->request->get('cron_expression', ''));
        $timeZone = trim((string) $request->request->get('time_zone', 'UTC'));
        $enabled = $request->request->getBoolean('enabled');

        if ($enabled && $cronExpression === '') {
            return $this->redirectToTab($instance->getId(), 'restart_planner', null, 'customer_instance_restart_planner_error_cron_required');
        }

        if ($enabled && !CronExpression::isValidExpression($cronExpression)) {
            return $this->redirectToTab($instance->getId(), 'restart_planner', null, 'customer_instance_restart_planner_error_cron_invalid');
        }

        $timeZone = $timeZone === '' ? 'UTC' : $timeZone;
        try {
            new \DateTimeZone($timeZone);
        } catch (\Exception) {
            return $this->redirectToTab($instance->getId(), 'restart_planner', null, 'customer_instance_restart_planner_error_timezone');
        }

        $schedule = $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, InstanceScheduleAction::Restart);
        if ($enabled) {
            if ($schedule === null) {
                $schedule = new InstanceSchedule(
                    $instance,
                    $customer,
                    InstanceScheduleAction::Restart,
                    $cronExpression,
                    $timeZone,
                    true,
                );
            } else {
                $schedule->update(InstanceScheduleAction::Restart, $cronExpression, $timeZone, true);
            }
            $this->entityManager->persist($schedule);
        } elseif ($schedule !== null) {
            $schedule->update(InstanceScheduleAction::Restart, $schedule->getCronExpression(), $schedule->getTimeZone(), false);
            $this->entityManager->persist($schedule);
        }

        $this->auditLogger->log($customer, 'instance.restart.schedule_updated', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'cron_expression' => $schedule?->getCronExpression(),
            'time_zone' => $schedule?->getTimeZone(),
            'enabled' => $schedule?->isEnabled(),
        ]);

        $this->entityManager->flush();

        return $this->redirectToTab($instance->getId(), 'restart_planner', 'customer_instance_restart_planner_saved', null);
    }

    #[Route(path: '/{id}/reinstall', name: 'customer_instance_reinstall', methods: ['POST'])]
    public function reinstall(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        if (!$request->request->getBoolean('confirm')) {
            return $this->redirectToTab($instance->getId(), 'reinstall', null, 'customer_instance_reinstall_error_confirm');
        }

        $blocked = $this->guardSetupRequirements($instance, SetupChecker::ACTION_INSTALL);
        if ($blocked !== null) {
            return $this->redirectToTab($instance->getId(), 'reinstall', null, 'customer_instance_reinstall_error_blocked');
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return $this->redirectToTab($instance->getId(), 'reinstall', null, 'customer_instance_reinstall_error_blocked');
        }

        $portBlock = $this->portBlockRepository->findByInstance($instance);
        $job = new Job('instance.reinstall', [
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
            'install_command' => $instance->getTemplate()->getInstallCommand(),
        ]);
        $this->entityManager->persist($job);

        $this->auditLogger->log($customer, 'instance.reinstall.queued', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return $this->redirectToTab($instance->getId(), 'reinstall', 'customer_instance_reinstall_queued', null);
    }

    #[Route(path: '/{id}/update', name: 'customer_instance_update', methods: ['POST'])]
    public function updateInstance(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $template = $instance->getTemplate();

        $blocked = $this->guardSetupRequirements($instance, SetupChecker::ACTION_UPDATE);
        if ($blocked !== null) {
            return $this->renderInstanceCard($instance, null, $blocked);
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return $this->renderInstanceCard($instance, null, $blockMessage);
        }

        if ($template->getSniperProfile() === null && $template->getUpdateCommand() === '') {
            return $this->renderInstanceCard($instance, null, 'No update command configured for this template.');
        }

        $targetBuildId = $instance->getLockedBuildId();
        $targetVersion = $instance->getLockedVersion();

        $job = new Job('sniper.update', $this->instanceJobPayloadBuilder->buildSniperUpdatePayload($instance, $targetBuildId, $targetVersion));
        $this->entityManager->persist($job);
        $instance->setLastUpdateQueuedAt(new \DateTimeImmutable());
        $this->entityManager->persist($instance);

        $this->auditLogger->log($customer, 'instance.update.queued', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'job_id' => $job->getId(),
            'locked_build_id' => $targetBuildId,
            'locked_version' => $targetVersion,
            'source' => 'customer.manual',
        ]);

        $this->entityManager->flush();

        return $this->renderInstanceCard($instance, 'Update queued.', null);
    }

    #[Route(path: '/{id}/settings', name: 'customer_instance_settings', methods: ['POST'])]
    public function updateSettings(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $detailMode = $request->query->getBoolean('detail');

        $policyRaw = (string) $request->request->get('update_policy', InstanceUpdatePolicy::Manual->value);
        $lockedBuildId = trim((string) $request->request->get('locked_build_id', ''));
        $lockedVersion = trim((string) $request->request->get('locked_version', ''));
        $cronExpression = trim((string) $request->request->get('cron_expression', ''));
        $timeZone = trim((string) $request->request->get('time_zone', 'UTC'));

        $policy = InstanceUpdatePolicy::tryFrom($policyRaw);
        if ($policy === null) {
            if ($detailMode) {
                return $this->redirectToTab($instance->getId(), 'settings', null, 'customer_instance_settings_error');
            }
            throw new BadRequestHttpException('Invalid update policy.');
        }

        if ($policy === InstanceUpdatePolicy::Auto && $cronExpression === '') {
            if ($detailMode) {
                return $this->redirectToTab($instance->getId(), 'settings', null, 'customer_instance_settings_error');
            }
            return $this->renderInstanceCard($instance, null, 'Auto updates require a cron schedule.');
        }

        if ($policy === InstanceUpdatePolicy::Auto && !CronExpression::isValidExpression($cronExpression)) {
            if ($detailMode) {
                return $this->redirectToTab($instance->getId(), 'settings', null, 'customer_instance_settings_error');
            }
            return $this->renderInstanceCard($instance, null, 'Cron expression is invalid.');
        }

        $timeZone = $timeZone === '' ? 'UTC' : $timeZone;
        try {
            new \DateTimeZone($timeZone);
        } catch (\Exception) {
            if ($detailMode) {
                return $this->redirectToTab($instance->getId(), 'settings', null, 'customer_instance_settings_error');
            }
            return $this->renderInstanceCard($instance, null, 'Time zone is invalid.');
        }

        $resolver = $instance->getTemplate()->getInstallResolver();
        $resolverType = is_array($resolver) ? (string) ($resolver['type'] ?? '') : '';
        if (in_array($resolverType, ['minecraft_vanilla', 'papermc_paper'], true)) {
            $channel = $resolverType === 'minecraft_vanilla' ? 'vanilla' : 'paper';
            $validationError = $this->minecraftCatalogService->validateSelection($channel, $lockedVersion, $lockedBuildId);
            if ($validationError !== null) {
                if ($detailMode) {
                    return $this->redirectToTab($instance->getId(), 'settings', null, 'customer_instance_settings_error');
                }
                return $this->renderInstanceCard($instance, null, $validationError);
            }
        }

        $instance->setUpdatePolicy($policy);
        $instance->setLockedBuildId($lockedBuildId !== '' ? $lockedBuildId : null);
        $instance->setLockedVersion($lockedVersion !== '' ? $lockedVersion : null);
        $this->entityManager->persist($instance);

        $schedule = $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, InstanceScheduleAction::Update);
        if ($policy === InstanceUpdatePolicy::Auto) {
            if ($schedule === null) {
                $schedule = new InstanceSchedule(
                    $instance,
                    $customer,
                    InstanceScheduleAction::Update,
                    $cronExpression,
                    $timeZone,
                    true,
                );
            } else {
                $schedule->update(InstanceScheduleAction::Update, $cronExpression, $timeZone, true);
            }
            $this->entityManager->persist($schedule);
        } elseif ($schedule !== null) {
            $schedule->update(InstanceScheduleAction::Update, $schedule->getCronExpression(), $schedule->getTimeZone(), false);
            $this->entityManager->persist($schedule);
        }

        $this->auditLogger->log($customer, 'instance.update.settings_updated', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'policy' => $policy->value,
            'locked_build_id' => $instance->getLockedBuildId(),
            'locked_version' => $instance->getLockedVersion(),
            'cron_expression' => $schedule?->getCronExpression(),
            'time_zone' => $schedule?->getTimeZone(),
            'schedule_enabled' => $schedule?->isEnabled(),
        ]);

        $this->entityManager->flush();

        if ($detailMode) {
            return $this->redirectToTab($instance->getId(), 'settings', 'customer_instance_settings_saved', null);
        }

        return $this->renderInstanceCard($instance, 'Update settings saved.', null);
    }

    #[Route(path: '/{id}/rollback', name: 'customer_instance_rollback', methods: ['POST'])]
    public function rollback(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        if ($instance->getPreviousBuildId() === null && $instance->getPreviousVersion() === null) {
            return $this->renderInstanceCard($instance, null, 'No previous build available for rollback.');
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return $this->renderInstanceCard($instance, null, $blockMessage);
        }

        $job = new Job('sniper.update', $this->instanceJobPayloadBuilder->buildSniperUpdatePayload(
            $instance,
            $instance->getPreviousBuildId(),
            $instance->getPreviousVersion(),
        ));
        $this->entityManager->persist($job);
        $instance->setLastUpdateQueuedAt(new \DateTimeImmutable());
        $this->entityManager->persist($instance);

        $this->auditLogger->log($customer, 'instance.update.rollback_queued', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'job_id' => $job->getId(),
            'rollback_build_id' => $instance->getPreviousBuildId(),
            'rollback_version' => $instance->getPreviousVersion(),
        ]);

        $this->entityManager->flush();

        return $this->renderInstanceCard($instance, 'Rollback queued.', null);
    }

    #[Route(path: '/{id}/power', name: 'customer_instance_power', methods: ['POST'])]
    public function power(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $action = trim((string) $request->request->get('action', ''));

        if ($instance->getStatus() === InstanceStatus::Suspended) {
            return $this->renderInstanceCard($instance, null, 'This instance is suspended.');
        }

        if ($action === 'start') {
            $blocked = $this->guardSetupRequirements($instance, SetupChecker::ACTION_START);
            if ($blocked !== null) {
                return $this->renderInstanceCard($instance, null, $blocked);
            }
        }

        $jobType = match ($action) {
            'start' => 'instance.start',
            'stop' => 'instance.stop',
            'restart' => 'instance.restart',
            default => null,
        };
        if ($jobType === null) {
            throw new BadRequestHttpException('Invalid action.');
        }

        $job = new Job($jobType, [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
        ]);
        $this->entityManager->persist($job);

        $this->auditLogger->log($customer, 'instance.power.queued', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'job_id' => $job->getId(),
            'action' => $action,
        ]);

        $this->entityManager->flush();

        $notice = match ($action) {
            'start' => 'Start queued.',
            'stop' => 'Stop queued.',
            'restart' => 'Restart queued.',
            default => 'Action queued.',
        };

        return $this->renderInstanceCard($instance, $notice, null);
    }

    private function guardSetupRequirements(Instance $instance, string $action): ?string
    {
        $status = $this->setupChecker->getSetupStatus($instance);
        if ($status['is_ready'] || !in_array($action, $status['blocked_actions'], true)) {
            return null;
        }

        $labels = array_map(static fn (array $entry): string => $entry['label'], $status['missing']);

        return $labels === []
            ? 'Setup requirements missing.'
            : sprintf('Setup required: %s.', implode(', ', $labels));
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
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
     * @param Instance[] $instances
     */
    private function normalizeInstances(array $instances): array
    {
        $schedules = $this->instanceScheduleRepository->findByInstancesAndAction($instances, InstanceScheduleAction::Update);
        $scheduleIndex = [];
        foreach ($schedules as $schedule) {
            $scheduleIndex[$schedule->getInstance()->getId()] = $schedule;
        }

        $portBlocks = $this->portBlockRepository->findByInstances($instances);
        $portBlockIndex = [];
        foreach ($portBlocks as $portBlock) {
            $assignedInstance = $portBlock->getInstance();
            if ($assignedInstance !== null) {
                $portBlockIndex[$assignedInstance->getId()] = $portBlock;
            }
        }

        return array_map(function (Instance $instance) use ($scheduleIndex, $portBlockIndex): array {
            $schedule = $scheduleIndex[$instance->getId()] ?? null;
            $portBlock = $portBlockIndex[$instance->getId()] ?? null;

            return $this->normalizeInstance($instance, $schedule, $portBlock);
        }, $instances);
    }

    private function normalizeInstance(Instance $instance, ?InstanceSchedule $schedule, ?\App\Module\Ports\Domain\Entity\PortBlock $portBlock, ?string $notice = null, ?string $error = null): array
    {
        $diskLimitBytes = $instance->getDiskLimitBytes();
        $diskUsedBytes = $instance->getDiskUsedBytes();
        $diskPercent = $diskLimitBytes > 0 ? ($diskUsedBytes / $diskLimitBytes) * 100 : 0;
        $connection = $this->buildConnectionData($instance, $portBlock);

        return [
            'id' => $instance->getId(),
            'template' => [
                'name' => $instance->getTemplate()->getDisplayName(),
                'game_key' => $instance->getTemplate()->getGameKey(),
                'install_resolver' => $instance->getTemplate()->getInstallResolver(),
            ],
            'node' => [
                'id' => $instance->getNode()->getId(),
                'name' => $instance->getNode()->getName(),
            ],
            'status' => $instance->getStatus()->value,
            'update_policy' => $instance->getUpdatePolicy()->value,
            'current_build_id' => $instance->getCurrentBuildId(),
            'current_version' => $instance->getCurrentVersion(),
            'previous_build_id' => $instance->getPreviousBuildId(),
            'previous_version' => $instance->getPreviousVersion(),
            'locked_build_id' => $instance->getLockedBuildId(),
            'locked_version' => $instance->getLockedVersion(),
            'last_update_queued_at' => $instance->getLastUpdateQueuedAt(),
            'disk_limit_bytes' => $diskLimitBytes,
            'disk_used_bytes' => $diskUsedBytes,
            'disk_limit_human' => $this->diskUsageFormatter->formatBytes($diskLimitBytes),
            'disk_used_human' => $this->diskUsageFormatter->formatBytes($diskUsedBytes),
            'disk_percent' => $diskPercent,
            'disk_state' => $instance->getDiskState()->value,
            'disk_last_scanned_at' => $instance->getDiskLastScannedAt(),
            'disk_scan_error' => $instance->getDiskScanError(),
            'connection' => $connection,
            'schedule' => $schedule === null ? null : [
                'cron_expression' => $schedule->getCronExpression(),
                'time_zone' => $schedule->getTimeZone() ?? 'UTC',
                'enabled' => $schedule->isEnabled(),
            ],
            'notice' => $notice,
            'error' => $error,
        ];
    }

    private function normalizeConfigFiles(array $configFiles): array
    {
        $normalized = [];
        foreach ($configFiles as $entry) {
            $path = trim((string) ($entry['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $description = trim((string) ($entry['description'] ?? ''));
            $name = basename($path);
            $dir = dirname($path);
            $normalized[] = [
                'path' => $path,
                'dir' => $dir === '.' ? '' : $dir,
                'name' => $name,
                'description' => $description,
            ];
        }

        return $normalized;
    }

    private function normalizeFastdlSettings(array $settings): array
    {
        return [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'base_url' => (string) ($settings['base_url'] ?? ''),
            'root_path' => (string) ($settings['root_path'] ?? ''),
        ];
    }

    private function normalizeBackupDefinitions(User $customer, Instance $instance): array
    {
        $definitions = $this->backupDefinitionRepository->findByCustomer($customer);

        $results = [];
        foreach ($definitions as $definition) {
            if ($definition->getTargetType() !== BackupTargetType::Game) {
                continue;
            }
            if ($definition->getTargetId() !== (string) $instance->getId()) {
                continue;
            }
            $schedule = $definition->getSchedule();
            $results[] = [
                'id' => $definition->getId(),
                'label' => $definition->getLabel(),
                'schedule' => $schedule === null ? null : [
                    'cron_expression' => $schedule->getCronExpression(),
                    'retention_days' => $schedule->getRetentionDays(),
                    'retention_count' => $schedule->getRetentionCount(),
                    'enabled' => $schedule->isEnabled(),
                ],
            ];
        }

        return $results;
    }

    private function normalizeJobsForInstance(Instance $instance): array
    {
        $jobs = $this->jobRepository->findLatest(100);
        $filtered = [];

        foreach ($jobs as $job) {
            $payload = $job->getPayload();
            if ((string) ($payload['instance_id'] ?? '') !== (string) $instance->getId()) {
                continue;
            }
            $filtered[] = [
                'id' => $job->getId(),
                'type' => $job->getType(),
                'status' => $job->getStatus()->value,
                'created_at' => $job->getCreatedAt(),
            ];
        }

        return array_slice($filtered, 0, 25);
    }

    private function buildTabs(int $instanceId): array
    {
        return [
            [
                'key' => 'overview',
                'label' => 'customer_instance_tab_overview',
                'href' => sprintf('/instances/%d?tab=overview', $instanceId),
            ],
            [
                'key' => 'configs',
                'label' => 'customer_instance_tab_configs',
                'href' => sprintf('/instances/%d?tab=configs', $instanceId),
            ],
            [
                'key' => 'files',
                'label' => 'customer_instance_tab_files',
                'href' => sprintf('/instances/%d?tab=files', $instanceId),
            ],
            [
                'key' => 'addons',
                'label' => 'customer_instance_tab_addons',
                'href' => sprintf('/instances/%d?tab=addons', $instanceId),
            ],
            [
                'key' => 'restart_planner',
                'label' => 'customer_instance_tab_restart_planner',
                'href' => sprintf('/instances/%d?tab=restart_planner', $instanceId),
            ],
            [
                'key' => 'backups',
                'label' => 'customer_instance_tab_backups',
                'href' => sprintf('/instances/%d?tab=backups', $instanceId),
            ],
            [
                'key' => 'console',
                'label' => 'customer_instance_tab_console',
                'href' => sprintf('/instances/%d?tab=console', $instanceId),
            ],
            [
                'key' => 'settings',
                'label' => 'customer_instance_tab_settings',
                'href' => sprintf('/instances/%d?tab=settings', $instanceId),
            ],
            [
                'key' => 'reinstall',
                'label' => 'customer_instance_tab_reinstall',
                'href' => sprintf('/instances/%d?tab=reinstall', $instanceId),
            ],
            [
                'key' => 'tasks',
                'label' => 'customer_instance_tab_tasks',
                'href' => sprintf('/instances/%d?tab=tasks', $instanceId),
            ],
        ];
    }

    private function resolveTab(string $tab): string
    {
        $allowed = [
            'overview',
            'configs',
            'files',
            'addons',
            'restart_planner',
            'backups',
            'console',
            'settings',
            'reinstall',
            'tasks',
        ];

        $tab = strtolower(trim($tab));

        return in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    private function resolveNoticeKey(string $notice): ?string
    {
        return match ($notice) {
            'customer_instance_restart_planner_saved',
            'customer_instance_reinstall_queued',
            'customer_instance_settings_saved',
            'customer_instance_update_saved' => $notice,
            default => null,
        };
    }

    private function resolveErrorKey(string $error): ?string
    {
        return match ($error) {
            'customer_instance_restart_planner_error_cron_required',
            'customer_instance_restart_planner_error_cron_invalid',
            'customer_instance_restart_planner_error_timezone',
            'customer_instance_reinstall_error_confirm',
            'customer_instance_reinstall_error_blocked',
            'customer_instance_settings_error' => $error,
            default => null,
        };
    }

    private function redirectToTab(int $instanceId, string $tab, ?string $notice, ?string $error): Response
    {
        $params = ['tab' => $tab];
        if ($notice !== null) {
            $params['notice'] = $notice;
        }
        if ($error !== null) {
            $params['error'] = $error;
        }

        $query = http_build_query($params);

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/instances/%d?%s', $instanceId, $query)]);
    }

    private function renderInstanceCard(Instance $instance, ?string $notice, ?string $error): Response
    {
        $schedule = $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, InstanceScheduleAction::Update);
        $portBlock = $this->portBlockRepository->findByInstance($instance);

        return new Response($this->twig->render('customer/instances/_card.html.twig', [
            'instance' => $this->normalizeInstance($instance, $schedule, $portBlock, $notice, $error),
            'minecraftCatalog' => $this->minecraftCatalogService->getUiCatalog(),
        ]));
    }

    private function buildConnectionData(Instance $instance, ?\App\Module\Ports\Domain\Entity\PortBlock $portBlock): array
    {
        $host = $instance->getNode()->getLastHeartbeatIp();
        $requiredPorts = $instance->getTemplate()->getRequiredPorts();
        $assignedPorts = [];
        $primaryPort = null;

        if ($portBlock !== null) {
            $ports = $portBlock->getPorts();

            foreach ($requiredPorts as $index => $definition) {
                if (!isset($ports[$index])) {
                    continue;
                }

                $label = (string) ($definition['name'] ?? 'port');
                $protocol = (string) ($definition['protocol'] ?? 'udp');
                $assignedPorts[] = [
                    'label' => sprintf('%s/%s', $label, $protocol),
                    'port' => $ports[$index],
                ];

                if ($primaryPort === null) {
                    $primaryPort = $ports[$index];
                }
            }

            if ($primaryPort === null && isset($ports[0])) {
                $primaryPort = $ports[0];
            }
        }

        $address = $host !== null && $primaryPort !== null ? sprintf('%s:%d', $host, $primaryPort) : null;

        return [
            'host' => $host,
            'address' => $address,
            'quick_connect' => $address !== null ? sprintf('steam://connect/%s', $address) : null,
            'port_block' => $portBlock === null ? null : [
                'start' => $portBlock->getStartPort(),
                'end' => $portBlock->getEndPort(),
            ],
            'assigned_ports' => $assignedPorts,
        ];
    }
}
