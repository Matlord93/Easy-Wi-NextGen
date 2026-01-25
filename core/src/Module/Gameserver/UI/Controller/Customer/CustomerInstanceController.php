<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceSchedule;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceScheduleAction;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\BackupDefinitionRepository;
use App\Repository\InstanceRepository;
use App\Repository\InstanceScheduleRepository;
use App\Repository\JobRepository;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Application\DiskUsageFormatter;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Gameserver\Application\InstanceJobPayloadBuilder;
use App\Module\Gameserver\Application\InstanceInstallService;
use App\Module\Gameserver\Application\InstanceQueryService;
use App\Module\Gameserver\Application\MinecraftCatalogService;
use App\Module\Core\Application\SetupChecker;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly InstanceQueryService $instanceQueryService,
        private readonly AuditLogger $auditLogger,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly DiskUsageFormatter $diskUsageFormatter,
        private readonly InstanceInstallService $instanceInstallService,
        private readonly MinecraftCatalogService $minecraftCatalogService,
        private readonly EncryptionService $encryptionService,
        private readonly SetupChecker $setupChecker,
        private readonly AppSettingsService $appSettingsService,
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
        $tabNotice = $this->resolveNoticeKey((string) $request->query->get('notice', ''));
        $tabError = $this->resolveErrorKey((string) $request->query->get('error', ''));

        return $this->renderInstanceDetail($instance, $customer, $activeTab, $tabNotice, $tabError);
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
        ], $install['payload'] ?? []));
        $this->entityManager->persist($job);

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

        if (!$this->appSettingsService->isGameserverStartStopAllowed()) {
            if ($this->prefersJsonResponse($request)) {
                return new JsonResponse(['error' => 'Start/Stop actions are disabled.'], JsonResponse::HTTP_FORBIDDEN);
            }
            return $this->renderInstanceCard($instance, null, 'Start/Stop actions are disabled.');
        }

        if ($instance->getStatus() === InstanceStatus::Suspended) {
            if ($this->prefersJsonResponse($request)) {
                return new JsonResponse(['error' => 'This instance is suspended.'], JsonResponse::HTTP_CONFLICT);
            }
            return $this->renderInstanceCard($instance, null, 'This instance is suspended.');
        }

        if ($action === 'start') {
            $blocked = $this->guardSetupRequirements($instance, SetupChecker::ACTION_START);
            if ($blocked !== null) {
                if ($this->prefersJsonResponse($request)) {
                    return new JsonResponse(['error' => $blocked], JsonResponse::HTTP_CONFLICT);
                }
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

        if ($this->prefersJsonResponse($request)) {
            return new JsonResponse([
                'job_id' => $job->getId(),
                'status' => 'queued',
                'message' => $notice,
            ], JsonResponse::HTTP_ACCEPTED);
        }

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
        $connection = $this->buildConnectionData($instance, $portBlock);
        $querySnapshot = $this->instanceQueryService->getSnapshot($instance, $portBlock, $queueQuery);
        $installStatus = $this->instanceInstallService->getInstallStatus($instance);

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
            'current_slots' => $instance->getCurrentSlots(),
            'max_slots' => $instance->getMaxSlots(),
            'lock_slots' => $instance->isLockSlots(),
            'install_ready' => $installStatus['is_ready'] ?? false,
            'install_error_code' => $installStatus['error_code'] ?? null,
            'query' => $querySnapshot,
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
        $tabs = [
            [
                'key' => 'overview',
                'label' => 'customer_instance_tab_overview',
                'href' => sprintf('/instances/%d?tab=overview', $instanceId),
            ],
            [
                'key' => 'setup',
                'label' => 'customer_instance_tab_setup',
                'href' => sprintf('/instances/%d?tab=setup', $instanceId),
            ],
            [
                'key' => 'configs',
                'label' => 'customer_instance_tab_configs',
                'href' => sprintf('/instances/%d?tab=configs', $instanceId),
            ],
            $this->appSettingsService->isCustomerDataManagerEnabled() ? [
                'key' => 'files',
                'label' => 'customer_instance_tab_files',
                'href' => sprintf('/instances/%d?tab=files', $instanceId),
            ] : null,
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
                'label' => $this->appSettingsService->getCustomerConsoleLabel() ?? 'customer_instance_tab_console',
                'label_is_key' => $this->appSettingsService->getCustomerConsoleLabel() === null,
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

        return array_values(array_filter($tabs));
    }

    private function resolveTab(string $tab): string
    {
        $allowed = [
            'overview',
            'setup',
            'configs',
            'addons',
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
            'fastdl' => $this->normalizeFastdlSettings($instance->getTemplate()->getFastdlSettings()),
            'restartSchedule' => $restartScheduleView,
            'backups' => $this->normalizeBackupDefinitions($customer, $instance),
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
        $defaults = [
            'SERVER_NAME' => $instance->getServerName(),
            'STEAM_GSLT' => $instance->getGslKey(),
            'STEAM_ACCOUNT' => $instance->getSteamAccount(),
        ];

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
            'setupSecrets' => $secretEntries,
            'setupMissingLabels' => $missingLabels,
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

        foreach ($this->buildDefaultCustomerVars() as $entry) {
            if (!isset($existing[$entry['key']])) {
                $requirements['vars'][] = $entry;
            }
        }

        return $requirements;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDefaultCustomerVars(): array
    {
        return [
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
        ];
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
