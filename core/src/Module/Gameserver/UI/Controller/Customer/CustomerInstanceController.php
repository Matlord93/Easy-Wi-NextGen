<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Application\DiskUsageFormatter;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\SetupChecker;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceSchedule;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\Domain\Enum\InstanceScheduleAction;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Gameserver\Application\InstanceInstallService;
use App\Module\Gameserver\Application\InstanceJobPayloadBuilder;
use App\Module\Gameserver\Application\InstanceQueryService;
use App\Module\Gameserver\Application\MinecraftCatalogService;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use App\Module\Ports\Infrastructure\Repository\PortAllocationRepository;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Repository\BackupDefinitionRepository;
use App\Repository\BackupRepository;
use App\Repository\BackupTargetRepository;
use App\Repository\GamePluginRepository;
use App\Repository\InstanceMetricSampleRepository;
use App\Repository\InstanceRepository;
use App\Repository\InstanceScheduleRepository;
use App\Repository\JobRepository;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[Route(path: '/instances')]
final class CustomerInstanceController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceScheduleRepository $instanceScheduleRepository,
        private readonly InstanceMetricSampleRepository $instanceMetricSampleRepository,
        private readonly PortBlockRepository $portBlockRepository,
        private readonly PortAllocationRepository $portAllocationRepository,
        private readonly BackupDefinitionRepository $backupDefinitionRepository,
        private readonly BackupRepository $backupRepository,
        private readonly BackupTargetRepository $backupTargetRepository,
        private readonly JobRepository $jobRepository,
        private readonly GamePluginRepository $gamePluginRepository,
        private readonly InstanceJobPayloadBuilder $instanceJobPayloadBuilder,
        private readonly InstanceQueryService $instanceQueryService,
        private readonly AuditLogger $auditLogger,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly DiskUsageFormatter $diskUsageFormatter,
        private readonly InstanceInstallService $instanceInstallService,
        private readonly MinecraftCatalogService $minecraftCatalogService,
        private readonly EncryptionService $encryptionService,
        private readonly AgentGameServerClient $agentGameServerClient,
        private readonly SetupChecker $setupChecker,
        private readonly AppSettingsService $appSettingsService,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
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
        $tabNotice = $this->resolveNoticeKey((string) $request->query->get('notice', ''));
        $tabError = $this->resolveErrorKey((string) $request->query->get('error', ''));

        return $this->renderInstanceDetail($instance, $customer, $activeTab, $tabNotice, $tabError, [], Response::HTTP_OK);
    }

    #[Route(path: '/{id}/overview', name: 'customer_instance_overview_page', methods: ['GET'])]
    public function overviewPage(Request $request, int $id): Response
    {
        return $this->renderNamedTabPage($request, $id, 'overview');
    }

    #[Route(path: '/{id}/console', name: 'customer_instance_console_page', methods: ['GET'])]
    public function consolePage(Request $request, int $id): Response
    {
        return $this->renderNamedTabPage($request, $id, 'console');
    }

    #[Route(path: '/{id}/backups', name: 'customer_instance_backups_page', methods: ['GET'])]
    public function backupsPage(Request $request, int $id): Response
    {
        return $this->renderNamedTabPage($request, $id, 'backups');
    }

    #[Route(path: '/{id}/tasks', name: 'customer_instance_tasks_page', methods: ['GET'])]
    public function tasksPage(Request $request, int $id): Response
    {
        return $this->renderNamedTabPage($request, $id, 'tasks');
    }

    #[Route(path: '/{id}/settings', name: 'customer_instance_settings_page', methods: ['GET'])]
    public function settingsPage(Request $request, int $id): Response
    {
        return $this->renderNamedTabPage($request, $id, 'settings');
    }

    #[Route(path: '/{id}/addons', name: 'customer_instance_addons_page', methods: ['GET'])]
    public function addonsPage(Request $request, int $id): Response
    {
        return $this->renderNamedTabPage($request, $id, 'addons');
    }

    #[Route(path: '/{id}/reinstall', name: 'customer_instance_reinstall_page', methods: ['GET'])]
    public function reinstallPage(Request $request, int $id): Response
    {
        return $this->renderNamedTabPage($request, $id, 'reinstall');
    }

    #[Route(path: '/{id}/setup/vars', name: 'customer_instance_setup_vars', methods: ['POST'])]
    public function saveSetupVars(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $requirements = $this->buildCustomerSetupRequirements($instance->getTemplate());
        $input = $request->request->all('vars');
        if (!is_array($input)) {
            throw new BadRequestHttpException('Invalid payload.');
        }

        $errors = [];
        $setupVars = $instance->getSetupVars();

        foreach ($requirements['vars'] as $entry) {
            $key = $entry['key'];
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = is_scalar($input[$key]) ? (string) $input[$key] : '';
            if ($value === '' && !$entry['required']) {
                unset($setupVars[$key]);
                continue;
            }

            if ($key === 'STEAM_LOGIN_MODE') {
                $value = strtolower(trim($value));
                if (!in_array($value, ['anonymous', 'account'], true)) {
                    $errors[$key] = 'Bitte wähle eine gültige Steam-Login-Art aus.';
                    continue;
                }
                $setupVars[$key] = $value;
                if ($value === 'anonymous') {
                    unset($setupVars['STEAM_PASSWORD']);
                    $instance->setSteamAccount(null);
                }
                continue;
            }

            $validationError = $this->setupChecker->validateRequirementValue($entry, $value);
            if ($validationError !== null) {
                $errors[$key] = $validationError;
                continue;
            }

            $setupVars[$key] = $entry['type'] === 'number' ? (string) $value : $value;

            switch ($key) {
                case 'SERVER_NAME':
                    $instance->setServerName($value !== '' ? $value : null);
                    break;
                case 'STEAM_GSLT':
                    $instance->setGslKey($value !== '' ? $value : null);
                    break;
                case 'STEAM_ACCOUNT':
                    $instance->setSteamAccount($value !== '' ? $value : null);
                    break;
            }
        }

        if ($errors !== []) {
            return $this->renderSetupTab($instance, $customer, [
                'vars' => [
                    'errors' => $errors,
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        $instance->setSetupVars($setupVars);
        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        return $this->renderSetupTab($instance, $customer, [
            'vars' => [
                'success' => 'Variablen gespeichert.',
            ],
        ]);
    }

    #[Route(path: '/{id}/setup/secrets', name: 'customer_instance_setup_secrets', methods: ['POST'])]
    public function saveSetupSecrets(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $requirements = $this->setupChecker->getCustomerRequirements($instance->getTemplate());
        $input = $request->request->all('secrets');
        if (!is_array($input)) {
            throw new BadRequestHttpException('Invalid payload.');
        }

        $errors = [];
        foreach ($requirements['secrets'] as $entry) {
            $key = $entry['key'];
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = is_scalar($input[$key]) ? (string) $input[$key] : '';
            if ($value === '') {
                if ($entry['required'] && !$instance->hasSetupSecret($key)) {
                    $errors[$key] = 'Value is required.';
                }
                continue;
            }

            $validationError = $this->setupChecker->validateRequirementValue($entry, $value);
            if ($validationError !== null) {
                $errors[$key] = $validationError;
                continue;
            }

            $payload = $this->encryptionService->encrypt($value);
            $instance->setSetupSecret($key, $payload);
        }

        if ($errors !== []) {
            return $this->renderSetupTab($instance, $customer, [
                'secrets' => [
                    'errors' => $errors,
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        return $this->renderSetupTab($instance, $customer, [
            'secrets' => [
                'success' => 'Secrets gespeichert.',
            ],
        ]);
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

    #[Route(path: '/{id}/install', name: 'customer_instance_install', methods: ['POST'])]
    public function install(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        $blocked = $this->guardSetupRequirements($instance, SetupChecker::ACTION_INSTALL);
        if ($blocked !== null) {
            if ($this->prefersJsonResponse($request)) {
                return new JsonResponse(['error' => $blocked], JsonResponse::HTTP_CONFLICT);
            }
            return $this->renderInstanceCard($instance, null, $blocked);
        }

        $install = $this->instanceInstallService->prepareInstall($instance);
        if (!$install['ok']) {
            $installError = $this->resolveInstallErrorMessage($install['error_code'] ?? null);
            if ($this->prefersJsonResponse($request)) {
                return new JsonResponse([
                    'error' => $installError,
                    'error_code' => $install['error_code'] ?? 'INSTALL_FAILED',
                ], JsonResponse::HTTP_CONFLICT);
            }
            return $this->renderInstanceCard($instance, null, $installError);
        }

        $job = new Job('sniper.install', array_merge([
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'cpu_limit' => (string) $instance->getCpuLimit(),
            'ram_limit' => (string) $instance->getRamLimit(),
            'disk_limit' => (string) $instance->getDiskLimit(),
            'base_dir' => $instance->getInstanceBaseDir() ?? $this->appSettingsService->getInstanceBaseDir(),
        ], $install['payload'] ?? []));
        $this->entityManager->persist($job);

        $instance->setStatus(InstanceStatus::Provisioning);
        $this->entityManager->persist($instance);

        $this->auditLogger->log($customer, 'instance.install.queued', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        if ($this->prefersJsonResponse($request)) {
            return new JsonResponse([
                'job_id' => $job->getId(),
                'status' => 'queued',
                'message' => 'Install queued.',
            ], JsonResponse::HTTP_ACCEPTED);
        }

        return $this->renderInstanceCard($instance, 'Install queued.', null);
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

        $payload = $this->instanceJobPayloadBuilder->buildSniperInstallPayload($instance);
        $payload['base_dir'] = $instance->getInstanceBaseDir() ?? $this->appSettingsService->getInstanceBaseDir();
        $payload['autostart'] = 'false';

        $job = new Job('instance.reinstall', $payload);
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
        $instance->setStatus(InstanceStatus::Provisioning);
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

        if (!$this->appSettingsService->isGameserverStartStopAllowed()) {
            if ($this->prefersJsonResponse($request)) {
                return new JsonResponse(['error' => 'Start/Stop actions are disabled.'], JsonResponse::HTTP_FORBIDDEN);
            }
            return $this->renderPowerHtmlResponse($request, $instance, null, 'Start/Stop actions are disabled.');
        }

        if ($instance->getStatus() === InstanceStatus::Suspended) {
            if ($this->prefersJsonResponse($request)) {
                return new JsonResponse(['error' => 'This instance is suspended.'], JsonResponse::HTTP_CONFLICT);
            }
            return $this->renderPowerHtmlResponse($request, $instance, null, 'This instance is suspended.');
        }

        if ($action === 'start') {
            $blocked = $this->guardSetupRequirements($instance, SetupChecker::ACTION_START);
            if ($blocked !== null) {
                if ($this->prefersJsonResponse($request)) {
                    return new JsonResponse(['error' => $blocked], JsonResponse::HTTP_CONFLICT);
                }
                return $this->renderPowerHtmlResponse($request, $instance, null, $blocked);
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

        $runtimeState = $this->resolveRuntimeState($instance, $this->instanceQueryService->getSnapshot($instance, null, false));
        if (($runtimeState['status'] ?? null) === 'unknown') {
            return $this->powerConflictResponse(
                $request,
                $instance,
                $runtimeState['reason'] ?? 'Instance runtime state is unknown.',
                'instance_state_unknown',
                15,
            );
        }

        if (in_array($instance->getStatus(), [InstanceStatus::Provisioning, InstanceStatus::PendingSetup], true)) {
            return $this->powerConflictResponse(
                $request,
                $instance,
                'Instance is currently transitioning. Please retry shortly.',
                'instance_transitioning',
                20,
            );
        }

        $activePowerJob = $this->findActivePowerJob($instance);
        if ($activePowerJob !== null) {
            if ($activePowerJob->getType() === $jobType) {
                if ($this->prefersJsonResponse($request)) {
                    return $this->responseEnvelopeFactory->success(
                        $request,
                        $activePowerJob->getId(),
                        'Power action already in progress.',
                        JsonResponse::HTTP_ACCEPTED,
                    );
                }

                return $this->renderPowerHtmlResponse($request, $instance, 'Power action already in progress.', null);
            }

            return $this->powerConflictResponse(
                $request,
                $instance,
                'Another power action is already running.',
                'power_action_in_progress',
                10,
            );
        }

        $payload = [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
        ];
        if (in_array($jobType, ['instance.start', 'instance.restart'], true)) {
            $payload = array_merge($this->instanceJobPayloadBuilder->buildRuntimePayload($instance), $payload);
        }

        $job = new Job($jobType, $payload);
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

        if ($this->prefersJsonResponse($request)) {
            return $this->responseEnvelopeFactory->success(
                $request,
                $job->getId(),
                $notice,
                JsonResponse::HTTP_ACCEPTED,
            );
        }

        return $this->renderPowerHtmlResponse($request, $instance, $notice, null);
    }

    private function renderPowerHtmlResponse(Request $request, Instance $instance, ?string $notice, ?string $error): Response
    {
        if ($this->isHtmxRequest($request)) {
            return $this->renderInstanceCard($instance, $notice, $error);
        }

        if ($notice !== null) {
            $request->getSession()->getFlashBag()->add('success', $notice);
        }
        if ($error !== null) {
            $request->getSession()->getFlashBag()->add('error', $error);
        }

        return new RedirectResponse($this->urlGenerator->generate('customer_instance_tasks_page', ['id' => $instance->getId()]));
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
        if (
            !$actor instanceof User
            || (!$actor->isAdmin() && $actor->getType() !== UserType::Customer)
        ) {
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

        if (!$customer->isAdmin() && $instance->getCustomer()->getId() !== $customer->getId()) {
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

            return $this->normalizeInstance($instance, $schedule, $portBlock, false);
        }, $instances);
    }

    private function normalizeInstance(
        Instance $instance,
        ?InstanceSchedule $schedule,
        ?\App\Module\Ports\Domain\Entity\PortBlock $portBlock,
        bool $queueQuery,
        ?string $notice = null,
        ?string $error = null,
    ): array {
        $diskLimitBytes = $instance->getDiskLimitBytes();
        $diskUsedBytes = $instance->getDiskUsedBytes();
        $diskPercent = $diskLimitBytes > 0 ? ($diskUsedBytes / $diskLimitBytes) * 100 : 0;
        $ports = $this->normalizeInstancePorts($instance);
        $connection = $this->buildConnectionData($instance, $portBlock, $ports);
        $querySnapshot = $this->instanceQueryService->getSnapshot($instance, $portBlock, $queueQuery);
        $installStatus = $this->instanceInstallService->getInstallStatus($instance);
        $instanceStatus = $instance->getStatus();
        $runtimeState = $this->resolveRuntimeState($instance, $querySnapshot);
        $instanceMetric = $this->instanceMetricSampleRepository->findLatestForInstance($instance);
        $bookedRamBytes = (int) $instance->getRamLimit() * 1024 * 1024;
        $usedRamBytes = $instanceMetric?->getMemUsedBytes();
        $ramPercent = ($usedRamBytes !== null && $bookedRamBytes > 0) ? (($usedRamBytes / $bookedRamBytes) * 100) : null;
        $metricsRuntimeStatus = $this->inferRuntimeStatusFromMetrics($instanceMetric);
        $runtimeStatus = $runtimeState['status'] ?? $metricsRuntimeStatus;
        if ($runtimeStatus === InstanceStatus::Stopped->value && $metricsRuntimeStatus === InstanceStatus::Running->value) {
            $runtimeStatus = InstanceStatus::Running->value;
        }
        $displayStatus = $this->resolveDisplayStatus($instance, $runtimeStatus);
        $activePowerJob = $this->findActivePowerJob($instance);
        $powerState = $this->resolvePowerState($instance, $runtimeStatus, $runtimeState, $activePowerJob);

        return [
            'id' => $instance->getId(),
            'template' => [
                'name' => $instance->getTemplate()->getDisplayName(),
                'game_key' => $instance->getTemplate()->getGameKey(),
                'install_resolver' => $instance->getTemplate()->getInstallResolver(),
            ],
            'server_name' => $instance->getServerName(),
            'node' => [
                'id' => $instance->getNode()->getId(),
                'name' => $instance->getNode()->getName(),
            ],
            'status' => $instance->getStatus()->value,
            'display_status' => $displayStatus,
            'runtime_status' => $runtimeStatus,
            'runtime_status_reason' => $runtimeState['reason'],
            'runtime_status_error_code' => $runtimeState['error_code'],
            'runtime_status_last_checked_at' => $runtimeState['checked_at'],
            'power' => $powerState,
            'update_policy' => $instance->getUpdatePolicy()->value,
            'current_build_id' => $instance->getCurrentBuildId(),
            'current_version' => $instance->getCurrentVersion(),
            'previous_build_id' => $instance->getPreviousBuildId(),
            'previous_version' => $instance->getPreviousVersion(),
            'locked_build_id' => $instance->getLockedBuildId(),
            'locked_version' => $instance->getLockedVersion(),
            'last_update_queued_at' => $instance->getLastUpdateQueuedAt(),
            'is_installed' => $instance->getCurrentBuildId() !== null
                || $instance->getCurrentVersion() !== null
                || $instance->getStartScriptPath() !== null,
            'disk_limit_bytes' => $diskLimitBytes,
            'disk_used_bytes' => $diskUsedBytes,
            'disk_limit_human' => $this->diskUsageFormatter->formatBytes($diskLimitBytes),
            'disk_used_human' => $this->diskUsageFormatter->formatBytes($diskUsedBytes),
            'disk_percent' => $diskPercent,
            'disk_state' => $instance->getDiskState()->value,
            'disk_last_scanned_at' => $instance->getDiskLastScannedAt(),
            'disk_scan_error' => $instance->getDiskScanError(),
            'current_slots' => $instance->getCurrentSlots(),
            'max_slots' => $instance->getMaxSlots(),
            'lock_slots' => $instance->isLockSlots(),
            'slots_configured' => $instance->getCurrentSlots() > 0 ? $instance->getCurrentSlots() : null,
            'slots_effective' => isset($querySnapshot['max_players']) && is_numeric($querySnapshot['max_players']) && (int) $querySnapshot['max_players'] > 0
                ? (int) $querySnapshot['max_players']
                : null,
            'install_ready' => $installStatus['is_ready'] ?? false,
            'install_error_code' => $installStatus['error_code'] ?? null,
            'query' => $querySnapshot,
            'query_players_online' => isset($querySnapshot['players']) && is_numeric($querySnapshot['players']) ? (int) $querySnapshot['players'] : null,
            'query_players_max' => isset($querySnapshot['max_players']) && is_numeric($querySnapshot['max_players']) ? (int) $querySnapshot['max_players'] : null,
            'query_checked_at' => $querySnapshot['checked_at'] ?? null,
            'query_reason' => $this->normalizeQueryReason(is_string($querySnapshot['error'] ?? null) ? $querySnapshot['error'] : null),
            'booked_cpu_cores' => (float) $instance->getCpuLimit(),
            'booked_ram_bytes' => $bookedRamBytes,
            'booked_ram_mb' => $instance->getRamLimit(),
            'instance_cpu_percent' => $instanceMetric?->getCpuPercent(),
            'instance_mem_used_bytes' => $usedRamBytes,
            'instance_mem_percent' => $ramPercent,
            'instance_tasks_current' => $instanceMetric?->getTasksCurrent(),
            'instance_metrics_checked_at' => $instanceMetric?->getCollectedAt(),
            'instance_metrics_reason' => $instanceMetric?->getErrorCode(),
            'connection' => $connection,
            'ports' => $ports,
            'ports_state' => $ports === [] ? 'pending' : 'ready',
            'has_ports' => $ports !== [],
            'schedule' => $schedule === null ? null : [
                'cron_expression' => $schedule->getCronExpression(),
                'time_zone' => $schedule->getTimeZone() ?? 'UTC',
                'enabled' => $schedule->isEnabled(),
                'last_run_at' => $schedule->getLastRunAt()?->format(DATE_ATOM),
                'last_status' => $schedule->getLastStatus(),
                'last_error_code' => $schedule->getLastErrorCode(),
                'next_run_at' => $this->calculateNextRunAt($schedule->getCronExpression(), $schedule->getTimeZone() ?? 'UTC'),
            ],
            'notice' => $notice,
            'error' => $error,
        ];
    }

    private function resolveDisplayStatus(Instance $instance, ?string $runtimeStatus): string
    {
        $status = $instance->getStatus();
        if (in_array($status, [InstanceStatus::PendingSetup, InstanceStatus::Provisioning, InstanceStatus::Suspended, InstanceStatus::Error], true)) {
            return $status->value;
        }

        if ($runtimeStatus === InstanceStatus::Running->value) {
            return InstanceStatus::Running->value;
        }

        if ($runtimeStatus === InstanceStatus::Stopped->value) {
            return InstanceStatus::Stopped->value;
        }

        return $status->value;
    }


    private function inferRuntimeStatusFromMetrics(mixed $instanceMetric): ?string
    {
        if ($instanceMetric === null || !method_exists($instanceMetric, 'getCollectedAt')) {
            return null;
        }

        $collectedAt = $instanceMetric->getCollectedAt();
        if (!$collectedAt instanceof \DateTimeImmutable) {
            return null;
        }

        $cutoff = new \DateTimeImmutable('-90 seconds');
        if ($collectedAt < $cutoff) {
            return null;
        }

        $tasksCurrent = method_exists($instanceMetric, 'getTasksCurrent') ? $instanceMetric->getTasksCurrent() : null;
        if (is_int($tasksCurrent) && $tasksCurrent > 0) {
            return InstanceStatus::Running->value;
        }

        $memUsedBytes = method_exists($instanceMetric, 'getMemUsedBytes') ? $instanceMetric->getMemUsedBytes() : null;
        if (is_int($memUsedBytes) && $memUsedBytes > 0) {
            return InstanceStatus::Running->value;
        }

        $cpuPercent = method_exists($instanceMetric, 'getCpuPercent') ? $instanceMetric->getCpuPercent() : null;
        if (is_float($cpuPercent) && $cpuPercent > 0.15) {
            return InstanceStatus::Running->value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $querySnapshot
     * @return array{status: ?string, reason: ?string, error_code: ?string, checked_at: string}
     */
    private function resolveRuntimeState(Instance $instance, array $querySnapshot): array
    {
        $checkedAt = (new \DateTimeImmutable())->format(DATE_ATOM);
        $queryStatus = $this->normalizeQueryStatus($querySnapshot['status'] ?? null, $querySnapshot['result']['online'] ?? null);
        $hasFreshQueryStatus = $queryStatus !== null && $this->hasFreshQueryRuntimeStatus($querySnapshot);

        if ($hasFreshQueryStatus) {
            return [
                'status' => $queryStatus,
                'reason' => null,
                'error_code' => null,
                'checked_at' => $checkedAt,
            ];
        }

        try {
            $status = $this->agentGameServerClient->getInstanceStatus($instance);
        } catch (\Throwable $exception) {
            $message = strtolower(trim($exception->getMessage()));
            if (str_contains($message, 'not valid json') || str_contains($message, 'not a json object') || str_contains($message, 'syntax error')) {
                return [
                    'status' => $hasFreshQueryStatus ? $queryStatus : null,
                    'reason' => null,
                    'error_code' => null,
                    'checked_at' => $checkedAt,
                ];
            }

            return [
                'status' => $hasFreshQueryStatus ? $queryStatus : null,
                'reason' => sprintf('Agent status probe failed: %s', $exception->getMessage()),
                'error_code' => 'agent_status_probe_failed',
                'checked_at' => $checkedAt,
            ];
        }

        $runtimeStatus = $this->normalizeRuntimeStatus(
            $status['status'] ?? null,
            $status['running'] ?? null,
            $status['online'] ?? null,
        );
        if ($runtimeStatus === null) {
            return [
                'status' => $hasFreshQueryStatus ? $queryStatus : null,
                'reason' => 'Agent status response missing recognizable runtime status field.',
                'error_code' => 'agent_status_missing_field',
                'checked_at' => $checkedAt,
            ];
        }

        return [
            'status' => $runtimeStatus,
            'reason' => null,
            'error_code' => null,
            'checked_at' => $checkedAt,
        ];
    }


    /**
     * @param array<string, mixed> $querySnapshot
     */
    private function hasFreshQueryRuntimeStatus(array $querySnapshot): bool
    {
        $checkedAtRaw = $querySnapshot['checked_at'] ?? null;
        if (!is_string($checkedAtRaw) || trim($checkedAtRaw) === '') {
            return false;
        }

        try {
            $checkedAt = new \DateTimeImmutable($checkedAtRaw);
        } catch (\Throwable) {
            return false;
        }

        return $checkedAt >= new \DateTimeImmutable('-90 seconds');
    }

    private function normalizeRuntimeStatus(mixed $status, mixed $running = null, mixed $online = null): ?string
    {
        if (is_string($status)) {
            return match (strtolower(trim($status))) {
                'online', 'running', 'up' => InstanceStatus::Running->value,
                'offline', 'stopped', 'down' => InstanceStatus::Stopped->value,
                default => null,
            };
        }

        if (is_bool($running)) {
            return $running ? InstanceStatus::Running->value : InstanceStatus::Stopped->value;
        }

        if (is_bool($online)) {
            return $online ? InstanceStatus::Running->value : InstanceStatus::Stopped->value;
        }

        return null;
    }

    private function normalizeQueryStatus(mixed $value, mixed $onlineHint = null): ?string
    {
        if (is_string($value)) {
            $normalized = match (strtolower(trim($value))) {
                'online', 'running', 'up' => InstanceStatus::Running->value,
                'offline', 'stopped', 'down' => InstanceStatus::Stopped->value,
                default => null,
            };
            if ($normalized !== null) {
                return $normalized;
            }
        }

        if (is_bool($onlineHint)) {
            return $onlineHint ? InstanceStatus::Running->value : InstanceStatus::Stopped->value;
        }

        return null;
    }


    private function normalizeQueryReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $normalized = strtolower(trim($reason));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'timeout')) {
            return 'Query request timed out.';
        }

        if (str_contains($normalized, 'connection refused')) {
            return 'Query endpoint unreachable (connection refused).';
        }

        if (str_contains($normalized, 'network is unreachable')) {
            return 'Query endpoint unreachable (network unreachable).';
        }

        return $reason;
    }

    private function findActivePowerJob(Instance $instance): ?Job
    {
        $instanceId = $instance->getId();
        if ($instanceId === null) {
            return null;
        }

        return $this->jobRepository->findLatestActiveByTypesAndInstanceId([
            'instance.start',
            'instance.stop',
            'instance.restart',
        ], $instanceId);
    }

    /**
     * @return array{disabled: bool, reason: ?string, active_job_id: ?string, retry_after: ?int}
     */
    private function resolvePowerState(Instance $instance, ?string $runtimeStatus, array $runtimeState, ?Job $activePowerJob): array
    {
        if (in_array($instance->getStatus(), [InstanceStatus::Provisioning, InstanceStatus::PendingSetup], true)) {
            return [
                'disabled' => true,
                'reason' => 'Instance is currently transitioning. Please retry shortly.',
                'active_job_id' => null,
                'retry_after' => 20,
            ];
        }

        if ($activePowerJob !== null) {
            return [
                'disabled' => true,
                'reason' => 'Power action already in progress.',
                'active_job_id' => $activePowerJob->getId(),
                'retry_after' => 10,
            ];
        }

        if ($runtimeStatus === 'unknown') {
            return [
                'disabled' => true,
                'reason' => (string) ($runtimeState['reason'] ?? 'Instance runtime state is unknown.'),
                'active_job_id' => null,
                'retry_after' => 15,
            ];
        }

        return [
            'disabled' => false,
            'reason' => null,
            'active_job_id' => null,
            'retry_after' => null,
        ];
    }

    private function powerConflictResponse(
        Request $request,
        Instance $instance,
        string $message,
        string $errorCode,
        int $retryAfter,
    ): Response {
        if ($this->prefersJsonResponse($request)) {
            return $this->responseEnvelopeFactory->error(
                $request,
                $message,
                $errorCode,
                JsonResponse::HTTP_CONFLICT,
                $retryAfter,
            );
        }

        return $this->renderInstanceCard($instance, null, $message);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizePlugins(Instance $instance): array
    {
        $plugins = $this->gamePluginRepository->findBy(
            ['template' => $instance->getTemplate()],
            ['name' => 'ASC'],
        );

        $installedVersions = [];
        $installedRaw = $instance->getConfigOverrides()['addons'] ?? [];
        if (is_array($installedRaw)) {
            foreach ($installedRaw as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $name = strtolower(trim((string) ($entry['name'] ?? '')));
                if ($name === '') {
                    continue;
                }
                $installedVersions[$name] = trim((string) ($entry['version'] ?? ''));
            }
        }

        return array_map(static function ($plugin) use ($installedVersions): array {
            $latestVersion = $plugin->getVersion();
            $installedVersion = $installedVersions[strtolower($plugin->getName())] ?? null;
            $installedVersion = is_string($installedVersion) && trim($installedVersion) !== '' ? trim($installedVersion) : null;

            return [
                'id' => $plugin->getId(),
                'name' => $plugin->getName(),
                'version' => $latestVersion,
                'latest_version' => $latestVersion,
                'installed_version' => $installedVersion,
                'is_installed' => $installedVersion !== null,
                'can_update' => $installedVersion !== null && $installedVersion !== $latestVersion,
                'can_remove' => $installedVersion !== null,
                'description' => $plugin->getDescription(),
            ];
        }, $plugins);
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
                    'time_zone' => $schedule->getTimeZone(),
                    'compression' => $schedule->getCompression(),
                    'stop_before' => $schedule->isStopBefore(),
                    'backup_target_id' => $schedule->getBackupTarget()?->getId(),
                    'last_run_at' => $schedule->getLastRunAt()?->format(DATE_ATOM),
                    'last_status' => $schedule->getLastStatus(),
                    'last_error_code' => $schedule->getLastErrorCode(),
                    'next_run_at' => $this->calculateNextRunAt($schedule->getCronExpression(), $schedule->getTimeZone()),
                ],
            ];
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBackupTargets(User $customer): array
    {
        $targets = $this->backupTargetRepository->findByCustomer($customer);

        return array_map(static fn ($target): array => [
            'id' => $target->getId(),
            'label' => $target->getLabel(),
            'type' => $target->getType()->value,
            'enabled' => $target->isEnabled(),
        ], array_values(array_filter($targets, static fn ($target): bool => $target->isEnabled())));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeBackupDefaults(): array
    {
        $settings = $this->appSettingsService->getSettings();

        return [
            'target_id' => is_scalar($settings[AppSettingsService::KEY_BACKUP_DEFAULT_TARGET_ID] ?? null)
                ? (string) $settings[AppSettingsService::KEY_BACKUP_DEFAULT_TARGET_ID]
                : null,
            'retention_count' => (int) ($settings[AppSettingsService::KEY_BACKUP_DEFAULT_RETENTION_COUNT] ?? 7),
            'retention_days' => (int) ($settings[AppSettingsService::KEY_BACKUP_DEFAULT_RETENTION_AGE_DAYS] ?? 30),
            'compression' => (string) ($settings[AppSettingsService::KEY_BACKUP_DEFAULT_COMPRESSION] ?? 'gzip'),
            'stop_before' => (bool) ($settings[AppSettingsService::KEY_BACKUP_STOP_BEFORE] ?? false),
        ];
    }

    private function calculateNextRunAt(string $cronExpression, string $timeZone): ?string
    {
        if (!CronExpression::isValidExpression($cronExpression)) {
            return null;
        }

        try {
            $tz = new \DateTimeZone($timeZone !== '' ? $timeZone : 'UTC');
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }

        try {
            $next = CronExpression::factory($cronExpression)->getNextRunDate('now', 0, true, $tz->getName());
        } catch (\Throwable) {
            return null;
        }

        return $next->format(DATE_ATOM);
    }

    private function normalizeBackupsForInstance(User $customer, Instance $instance): array
    {
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

        return array_map(static fn (\App\Module\Core\Domain\Entity\Backup $backup): array => [
            'id' => $backup->getId(),
            'definition_id' => $backup->getDefinition()->getId(),
            'status' => $backup->getStatus()->value,
            'job_id' => $backup->getJob()?->getId(),
            'created_at' => $backup->getCreatedAt()->format(DATE_ATOM),
            'completed_at' => $backup->getCompletedAt()?->format(DATE_ATOM),
            'size_bytes' => $backup->getSizeBytes(),
            'checksum_sha256' => $backup->getChecksumSha256(),
            'error_code' => $backup->getErrorCode(),
            'error_message' => $backup->getErrorMessage(),
        ], $backups);
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
        $tabs = [
            [
                'key' => 'overview',
                'label' => 'customer_instance_tab_overview',
                'href' => $this->instanceTabUrl($instanceId, 'overview'),
            ],
            [
                'key' => 'console',
                'label' => $this->appSettingsService->getCustomerConsoleLabel() ?? 'customer_instance_tab_console',
                'label_is_key' => $this->appSettingsService->getCustomerConsoleLabel() === null,
                'href' => $this->instanceTabUrl($instanceId, 'console'),
            ],
            [
                'key' => 'setup',
                'label' => 'customer_instance_tab_setup',
                'href' => $this->instanceTabUrl($instanceId, 'setup'),
            ],
            $this->appSettingsService->isCustomerDataManagerEnabled() ? [
                'key' => 'files',
                'label' => 'customer_instance_tab_files',
                'href' => $this->instanceTabUrl($instanceId, 'files'),
            ] : null,
            [
                'key' => 'backups',
                'label' => 'customer_instance_tab_backups',
                'href' => $this->instanceTabUrl($instanceId, 'backups'),
            ],
            [
                'key' => 'addons',
                'label' => 'customer_instance_tab_addons',
                'href' => $this->instanceTabUrl($instanceId, 'addons'),
            ],
            [
                'key' => 'tasks',
                'label' => 'customer_instance_tab_tasks',
                'href' => $this->instanceTabUrl($instanceId, 'tasks'),
            ],
            [
                'key' => 'settings',
                'label' => 'customer_instance_tab_settings',
                'href' => $this->instanceTabUrl($instanceId, 'settings'),
            ],
            [
                'key' => 'reinstall',
                'label' => 'customer_instance_tab_reinstall',
                'href' => $this->instanceTabUrl($instanceId, 'reinstall'),
            ],
        ];

        return array_values(array_filter($tabs));
    }

    private function instanceTabUrl(int $instanceId, string $tab): string
    {
        return match ($tab) {
            'overview' => $this->urlGenerator->generate('customer_instance_overview_page', ['id' => $instanceId]),
            'console' => $this->urlGenerator->generate('customer_instance_console_page', ['id' => $instanceId]),
            'backups' => $this->urlGenerator->generate('customer_instance_backups_page', ['id' => $instanceId]),
            'addons' => $this->urlGenerator->generate('customer_instance_addons_page', ['id' => $instanceId]),
            'tasks' => $this->urlGenerator->generate('customer_instance_tasks_page', ['id' => $instanceId]),
            'settings' => $this->urlGenerator->generate('customer_instance_settings_page', ['id' => $instanceId]),
            'reinstall' => $this->urlGenerator->generate('customer_instance_reinstall_page', ['id' => $instanceId]),
            'files' => $this->urlGenerator->generate('customer_instance_files', ['id' => $instanceId]),
            default => $this->urlGenerator->generate('customer_instance_detail', [
                'id' => $instanceId,
                'tab' => $tab,
            ]),
        };
    }

    private function renderNamedTabPage(Request $request, int $id, string $tab): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        return $this->renderInstanceDetail($instance, $customer, $this->resolveTab($tab), null, null);
    }

    private function resolveTab(string $tab): string
    {
        $allowed = [
            'overview',
            'setup',
            'addons',
            'configs',
            'restart_planner',
            'backups',
            'console',
            'settings',
            'reinstall',
            'tasks',
        ];

        if ($this->appSettingsService->isCustomerDataManagerEnabled()) {
            $allowed[] = 'files';
        }

        $tab = strtolower(trim($tab));

        return in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    private function renderSetupTab(Instance $instance, User $customer, array $messages, int $statusCode = Response::HTTP_OK): Response
    {
        return $this->renderInstanceDetail($instance, $customer, 'setup', null, null, $messages, $statusCode);
    }

    private function renderInstanceDetail(
        Instance $instance,
        User $customer,
        string $activeTab,
        ?string $tabNotice,
        ?string $tabError,
        array $setupMessages = [],
        int $statusCode = Response::HTTP_OK,
    ): Response {
        $updateSchedule = $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, InstanceScheduleAction::Update);
        $portBlock = $this->portBlockRepository->findByInstance($instance);
        $instanceView = $this->normalizeInstance($instance, $updateSchedule, $portBlock, true);

        $configFiles = $this->normalizeConfigFiles($instance->getTemplate()->getConfigFiles());
        $restartSchedule = $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, InstanceScheduleAction::Restart);
        $restartScheduleView = $restartSchedule === null ? null : [
            'cron_expression' => $restartSchedule->getCronExpression(),
            'time_zone' => $restartSchedule->getTimeZone() ?? 'UTC',
            'enabled' => $restartSchedule->isEnabled(),
            'last_run_at' => $restartSchedule->getLastQueuedAt()?->format(DATE_ATOM),
            'next_run_at' => $this->calculateNextRunAt($restartSchedule->getCronExpression(), $restartSchedule->getTimeZone() ?? 'UTC'),
        ];

        $tabs = $this->buildTabs($instance->getId() ?? 0);

        $payload = [
            'instance' => $instanceView,
            'template' => [
                'start_params' => $instance->getTemplate()->getStartParams(),
                'env_vars' => $instance->getTemplate()->getEnvVars(),
            ],
            'configFiles' => $configFiles,
            'pluginPaths' => $instance->getTemplate()->getPluginPaths(),
            'availablePlugins' => $this->normalizePlugins($instance),
            'fastdl' => $this->normalizeFastdlSettings($instance->getTemplate()->getFastdlSettings()),
            'restartSchedule' => $restartScheduleView,
            'backups' => $this->normalizeBackupDefinitions($customer, $instance),
            'backupHistory' => $this->normalizeBackupsForInstance($customer, $instance),
            'backupTargets' => $this->normalizeBackupTargets($customer),
            'backupDefaults' => $this->normalizeBackupDefaults(),
            'jobs' => $this->normalizeJobsForInstance($instance),
            'activeNav' => 'instances',
            'tabs' => $tabs,
            'activeTab' => $activeTab,
            'tabTemplate' => sprintf('customer/instances/tabs/%s.html.twig', $activeTab),
            'tabNotice' => $tabNotice,
            'tabError' => $tabError,
            'minecraftCatalog' => $this->minecraftCatalogService->getUiCatalog(),
        ];

        if ($activeTab === 'setup') {
            $payload = array_merge($payload, $this->buildSetupContext($instance, $setupMessages));
        }

        return new Response($this->twig->render('customer/instances/show.html.twig', $payload), $statusCode);
    }

    /**
     * @param array{vars?: array{errors?: array<string, string>, success?: string}, secrets?: array{errors?: array<string, string>, success?: string}} $messages
     * @return array<string, mixed>
     */
    private function buildSetupContext(Instance $instance, array $messages): array
    {
        $status = $this->setupChecker->getSetupStatus($instance);
        $requirements = $this->buildCustomerSetupRequirements($instance->getTemplate());
        $setupVars = $instance->getSetupVars();
        $setupSecrets = $instance->getSetupSecrets();
        $defaults = [];
        foreach ($instance->getTemplate()->getEnvVars() as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $defaults[$key] = (string) ($entry['value'] ?? '');
        }
        $defaults['SERVER_NAME'] = $instance->getServerName() ?? ($defaults['SERVER_NAME'] ?? '');
        $defaults['STEAM_GSLT'] = $instance->getGslKey() ?? ($defaults['STEAM_GSLT'] ?? '');
        $defaults['STEAM_ACCOUNT'] = $instance->getSteamAccount() ?? ($defaults['STEAM_ACCOUNT'] ?? '');
        $defaults['STEAM_LOGIN_MODE'] = $this->resolveSteamLoginMode($setupVars, $instance->getSteamAccount());

        $varEntries = array_map(function (array $entry) use ($setupVars, $messages, $defaults): array {
            $key = $entry['key'];

            return [
                'key' => $key,
                'label' => $entry['label'],
                'type' => $entry['type'],
                'required' => $entry['required'],
                'helptext' => $entry['helptext'],
                'value' => $setupVars[$key] ?? ($defaults[$key] ?? ''),
                'error' => $messages['vars']['errors'][$key] ?? null,
            ];
        }, $requirements['vars']);

        $secretEntries = array_map(function (array $entry) use ($setupSecrets, $messages): array {
            $key = $entry['key'];

            return [
                'key' => $key,
                'label' => $entry['label'],
                'type' => $entry['type'],
                'required' => $entry['required'],
                'helptext' => $entry['helptext'],
                'is_set' => array_key_exists($key, $setupSecrets),
                'error' => $messages['secrets']['errors'][$key] ?? null,
            ];
        }, $requirements['secrets']);

        $configKeys = ['SERVER_NAME', 'SERVER_PASSWORD', 'RCON_PASSWORD'];
        $configEntries = array_values(array_filter($varEntries, fn (array $entry): bool => in_array($entry['key'], $configKeys, true)));
        $variableEntries = array_values(array_filter($varEntries, fn (array $entry): bool => !in_array($entry['key'], $configKeys, true) && !$this->isPortKey($entry['key'])));

        $customerKeys = [];
        foreach (array_merge($requirements['vars'], $requirements['secrets']) as $entry) {
            $customerKeys[$entry['key']] = true;
        }
        $missingLabels = [];
        foreach ($status['missing'] as $entry) {
            if (isset($customerKeys[$entry['key']])) {
                $missingLabels[] = $entry['label'];
            }
        }

        return [
            'setupStatus' => $status,
            'setupVars' => $varEntries,
            'setupConfigVars' => $configEntries,
            'setupVariableVars' => $variableEntries,
            'setupSecrets' => $secretEntries,
            'setupMissingLabels' => $missingLabels,
            'steamLoginMode' => $defaults['STEAM_LOGIN_MODE'],
            'setupMessages' => [
                'vars' => $messages['vars']['success'] ?? null,
                'secrets' => $messages['secrets']['success'] ?? null,
            ],
        ];
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

    /**
     * @return array{vars: array<int, array<string, mixed>>, secrets: array<int, array<string, mixed>>}
     */
    private function buildCustomerSetupRequirements(Template $template): array
    {
        $requirements = $this->setupChecker->getCustomerRequirements($template);
        $existing = [];
        foreach (array_merge($requirements['vars'], $requirements['secrets']) as $entry) {
            $existing[$entry['key']] = true;
        }

        foreach ($template->getEnvVars() as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key === '' || isset($existing[$key])) {
                continue;
            }

            $requirements['vars'][] = [
                'key' => $key,
                'label' => $entry['label'] ?? $key,
                'type' => $entry['type'] ?? 'text',
                'required' => false,
                'scope' => 'customer_allowed',
                'validation' => $entry['validation'] ?? null,
                'helptext' => $entry['helptext'] ?? '',
            ];
            $existing[$key] = true;
        }

        foreach ($this->buildDefaultCustomerVars($template) as $entry) {
            if (!isset($existing[$entry['key']])) {
                $requirements['vars'][] = $entry;
            }
        }

        return $requirements;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDefaultCustomerVars(Template $template): array
    {
        $envKeys = [];
        foreach ($template->getEnvVars() as $entry) {
            if (is_array($entry) && isset($entry['key'])) {
                $key = strtoupper(trim((string) $entry['key']));
                if ($key !== '') {
                    $envKeys[$key] = true;
                }
            }
        }

        $installCommand = $template->getInstallCommand();
        $usesSteamLogin = str_contains($installCommand, '{{STEAM_LOGIN}}')
            || str_contains($installCommand, '{{STEAM_ACCOUNT}}');

        $entries = [
            [
                'key' => 'SERVER_NAME',
                'label' => 'Servername',
                'type' => 'text',
                'required' => false,
                'scope' => 'customer_allowed',
                'validation' => null,
                'helptext' => 'Optional, wird als Hostname verwendet.',
            ],
            [
                'key' => 'SERVER_PASSWORD',
                'label' => 'Serverpasswort',
                'type' => 'password',
                'required' => false,
                'scope' => 'customer_allowed',
                'validation' => null,
                'helptext' => 'Optional, nur nötig für private Server.',
            ],
            [
                'key' => 'RCON_PASSWORD',
                'label' => 'RCON Passwort',
                'type' => 'password',
                'required' => false,
                'scope' => 'customer_allowed',
                'validation' => null,
                'helptext' => 'Optional, für Remote-Konsole.',
            ],
            [
                'key' => 'STEAM_GSLT',
                'label' => 'Steam GSLT',
                'type' => 'text',
                'required' => false,
                'scope' => 'customer_allowed',
                'validation' => null,
                'helptext' => 'Benötigt für manche Source-Server.',
            ],
            [
                'key' => 'STEAM_LOGIN_MODE',
                'label' => 'Steam Login',
                'type' => 'text',
                'required' => false,
                'scope' => 'customer_allowed',
                'validation' => null,
                'helptext' => 'Standard ist Anonymous. Optional kann ein eigener Steam Account genutzt werden.',
            ],
            [
                'key' => 'STEAM_ACCOUNT',
                'label' => 'Steam Benutzername',
                'type' => 'text',
                'required' => false,
                'scope' => 'customer_allowed',
                'validation' => null,
                'helptext' => 'Nur nötig, wenn kein Anonymous-Login möglich ist.',
            ],
            [
                'key' => 'STEAM_PASSWORD',
                'label' => 'Steam Passwort',
                'type' => 'password',
                'required' => false,
                'scope' => 'customer_allowed',
                'validation' => null,
                'helptext' => 'Nur nötig, wenn Steam Account genutzt wird.',
            ],
            [
                'key' => 'GAME_TYPE',
                'label' => 'game_type',
                'type' => 'number',
                'required' => false,
                'scope' => 'customer_allowed',
                'validation' => null,
                'helptext' => 'Nur für Source-Server.',
            ],
            [
                'key' => 'GAME_MODE',
                'label' => 'game_mode',
                'type' => 'number',
                'required' => false,
                'scope' => 'customer_allowed',
                'validation' => null,
                'helptext' => 'Nur für Source-Server.',
            ],
        ];

        return array_values(array_filter($entries, static function (array $entry) use ($envKeys, $usesSteamLogin): bool {
            $key = strtoupper((string) $entry['key']);
            if (in_array($key, ['STEAM_LOGIN_MODE', 'STEAM_ACCOUNT', 'STEAM_PASSWORD'], true)) {
                return $usesSteamLogin || isset($envKeys[$key]);
            }

            return isset($envKeys[$key]);
        }));
    }

    private function resolveSteamLoginMode(array $setupVars, ?string $steamAccount): string
    {
        $mode = strtolower(trim((string) ($setupVars['STEAM_LOGIN_MODE'] ?? '')));
        if (in_array($mode, ['anonymous', 'account'], true)) {
            return $mode;
        }

        return $steamAccount ? 'account' : 'anonymous';
    }

    private function isPortKey(string $key): bool
    {
        return str_contains(strtoupper($key), 'PORT');
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
            'instance' => $this->normalizeInstance($instance, $schedule, $portBlock, false, $notice, $error),
            'minecraftCatalog' => $this->minecraftCatalogService->getUiCatalog(),
        ]));
    }

    private function prefersJsonResponse(Request $request): bool
    {
        $accept = strtolower((string) $request->headers->get('Accept', ''));
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        return $request->getRequestFormat(null) === 'json';
    }

    private function isHtmxRequest(Request $request): bool
    {
        return strtolower((string) $request->headers->get('HX-Request', '')) === 'true';
    }

    private function resolveInstallErrorMessage(?string $errorCode): string
    {
        return match ($errorCode) {
            'NO_PORTS_AVAILABLE' => 'No ports available for this instance.',
            'INSTALL_COMMAND_RESOLUTION_FAILED' => 'Install command could not be resolved for this template.',
            'INSTALL_COMMAND_MISSING' => 'No install command configured for this template.',
            'START_PARAMS_MISSING' => 'No start parameters configured for this template.',
            default => 'Install prerequisites not met.',
        };
    }

    private function buildConnectionData(Instance $instance, ?\App\Module\Ports\Domain\Entity\PortBlock $portBlock, array $ports): array
    {
        $host = $instance->getNode()->getLastHeartbeatIp();
        $assignedPorts = [];
        $primaryPort = null;

        foreach ($ports as $portEntry) {
            $label = (string) ($portEntry['name'] ?? 'port');
            $protocol = (string) ($portEntry['protocol'] ?? 'udp');
            $port = (int) ($portEntry['port'] ?? 0);
            if ($port <= 0) {
                continue;
            }

            $assignedPorts[] = [
                'label' => sprintf('%s/%s', $label, $protocol),
                'port' => $port,
            ];

            if ($primaryPort === null) {
                $primaryPort = $port;
            }
        }

        if ($primaryPort === null) {
            $legacyPort = $instance->getAssignedPort();
            if ($legacyPort !== null && $legacyPort > 0) {
                $primaryPort = $legacyPort;
                $assignedPorts[] = [
                    'label' => 'legacy/udp',
                    'port' => $legacyPort,
                ];
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

    /**
     * @return array<int, array{name: string, port: int, protocol: string, bind_ip: ?string, is_auto: bool, purpose: ?string, created_at: string}>
     */
    private function normalizeInstancePorts(Instance $instance): array
    {
        $allocations = $this->portAllocationRepository->findByInstanceOrdered($instance);

        $ports = array_map(static function (\App\Module\Ports\Domain\Entity\PortAllocation $allocation): array {
            $strategy = strtolower($allocation->getAllocationStrategy());

            return [
                'name' => $allocation->getRoleKey(),
                'port' => $allocation->getPort(),
                'protocol' => strtolower($allocation->getProto()),
                'bind_ip' => null,
                'is_auto' => !in_array($strategy, ['manual', 'custom', 'user', 'user_selected', 'admin', 'admin_selected'], true),
                'purpose' => $allocation->getPurpose(),
                'created_at' => $allocation->getCreatedAt()->format(DATE_ATOM),
            ];
        }, $allocations);

        if ($ports === []) {
            $legacyPort = $instance->getAssignedPort();
            if ($legacyPort !== null && $legacyPort > 0) {
                $ports[] = [
                    'name' => 'legacy',
                    'port' => $legacyPort,
                    'protocol' => 'udp',
                    'bind_ip' => null,
                    'is_auto' => true,
                    'purpose' => 'legacy_assigned_port',
                    'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ];
            }
        }

        return $ports;
    }
}
