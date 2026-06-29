<?php

declare(strict_types=1);

namespace App\Module\Musicbot\UI\Controller\Admin;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Attribute\RequiresModule;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Domain\Enum\ModuleKey;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotTeamspeakBackendConfig;
use App\Module\Musicbot\Domain\Enum\MusicbotInstanceStatus;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use App\Module\Musicbot\Domain\Enum\MusicbotTeamspeakBackendStatus;
use App\Module\Musicbot\Domain\Enum\MusicbotTeamspeakProfile;
use App\Module\Musicbot\Application\MusicbotPayloadLogSummarizer;
use App\Module\Musicbot\Application\MusicbotRuntimeEventService;
use App\Module\Musicbot\Application\MusicbotAutoDjService;
use App\Module\Musicbot\Application\MusicbotScheduleService;
use App\Module\Musicbot\Application\MusicbotSecretConfigService;
use App\Module\Musicbot\Application\MusicbotStreamService;
use App\Module\Musicbot\Application\MusicbotConfigApplyPayloadBuilder;
use App\Module\Musicbot\Application\MusicbotRuntimeStatusNormalizer;
use App\Module\Musicbot\Application\MusicbotQuotaService;
use App\Module\Musicbot\Application\MusicbotWorkflowService;
use App\Module\Musicbot\Domain\Entity\MusicbotWorkflow;
use App\Repository\MusicbotAutoDjSettingsRepository;
use App\Repository\MusicbotStreamSettingsRepository;
use App\Repository\MusicbotWorkflowRepository;
use App\Module\Musicbot\Application\PluginRegistryService;
use App\Module\Musicbot\Application\MusicbotPlanLimitResolver;
use App\Module\Musicbot\Domain\Entity\MusicbotCustomerLimits;
use App\Repository\AgentRepository;
use App\Repository\MusicbotConnectionRepository;
use App\Repository\MusicbotInstanceRepository;
use App\Repository\MusicbotPluginRepository;
use App\Repository\MusicbotCustomerLimitsRepository;
use App\Repository\MusicbotQueueItemRepository;
use App\Repository\MusicbotScheduleRepository;
use App\Repository\MusicbotTeamspeakBackendConfigRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[Route(path: '/admin/musicbots')]
#[RequiresModule(ModuleKey::Musicbot->value)]
final class AdminMusicbotController
{
    private const ACTIONS = ['install', 'uninstall', 'start', 'stop', 'restart', 'status', 'update', 'repair'];

    public function __construct(
        private readonly MusicbotInstanceRepository $instanceRepository,
        private readonly MusicbotConnectionRepository $connectionRepository,
        private readonly MusicbotTeamspeakBackendConfigRepository $teamspeakBackendConfigRepository,
        private readonly MusicbotQueueItemRepository $queueItemRepository,
        private readonly MusicbotRuntimeEventService $runtimeEventService,
        private readonly MusicbotAutoDjService $autoDjService,
        private readonly MusicbotAutoDjSettingsRepository $autoDjSettingsRepository,
        private readonly MusicbotScheduleService $scheduleService,
        private readonly MusicbotScheduleRepository $scheduleRepository,
        private readonly MusicbotStreamService $streamService,
        private readonly MusicbotStreamSettingsRepository $streamSettingsRepository,
        private readonly MusicbotWorkflowService $workflowService,
        private readonly MusicbotWorkflowRepository $workflowRepository,
        private readonly MusicbotSecretConfigService $secretConfigService,
        private readonly MusicbotConfigApplyPayloadBuilder $configApplyPayloadBuilder,
        private readonly MusicbotRuntimeStatusNormalizer $runtimeStatusNormalizer,
        private readonly PluginRegistryService $pluginRegistryService,
        private readonly MusicbotPluginRepository $pluginRepository,
        private readonly MusicbotCustomerLimitsRepository $customerLimitsRepository,
        private readonly MusicbotPlanLimitResolver $planLimitResolver,
        private readonly MusicbotQuotaService $quotaService,
        private readonly UserRepository $userRepository,
        private readonly AgentRepository $agentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly AgentJobDispatcher $jobDispatcher,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'admin_musicbot_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin($request);
        $instances = $this->instanceRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/musicbot/index.html.twig', [
            'activeNav' => 'musicbots',
            'rows' => $this->buildIndexRows($instances),
            'instances' => $instances,
            'summary' => $this->buildSummary($instances),
            'actions' => self::ACTIONS,
        ]));
    }



    #[Route(path: '/limits', name: 'admin_musicbot_limits', methods: ['GET'])]
    public function limits(Request $request): Response
    {
        $this->requireAdmin($request);
        $customers = $this->userRepository->findCustomers();
        $rows = [];
        foreach ($customers as $customer) {
            $override = $this->customerLimitsRepository->findByCustomer($customer);
            $rows[] = [
                'customer' => $customer,
                'resolved' => $this->planLimitResolver->resolve($customer)->toArray(),
                'override' => $override,
            ];
        }

        return new Response($this->twig->render('admin/musicbot/limits.html.twig', [
            'activeNav' => 'musicbots',
            'rows' => $rows,
            'defaults' => MusicbotPlanLimitResolver::planDefaults(),
        ]));
    }

    #[Route(path: '/limits/{customerId}', name: 'admin_musicbot_limits_save', requirements: ['customerId' => '\\d+'], methods: ['POST'])]
    public function saveLimits(Request $request, int $customerId): Response
    {
        $actor = $this->requireAdmin($request);
        $customer = $this->userRepository->find($customerId);
        if (!$customer instanceof User || $customer->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Customer not found.');
        }

        $limits = $this->customerLimitsRepository->findByCustomer($customer) ?? new MusicbotCustomerLimits($customer);
        $this->entityManager->persist($limits);

        foreach ([
            'max_musicbots' => 'setMaxMusicbots',
            'max_tracks' => 'setMaxTracks',
            'max_storage_mb' => 'setMaxStorageMb',
            'max_upload_size_mb' => 'setMaxUploadSizeMb',
            'max_queue_items' => 'setMaxQueueItems',
            'max_playlists' => 'setMaxPlaylists',
            'max_playlist_items' => 'setMaxPlaylistItems',
            'max_plugins' => 'setMaxPlugins',
        ] as $field => $setter) {
            $raw = trim((string) $request->request->get($field, ''));
            $limits->{$setter}($raw === '' ? null : (int) $raw);
        }

        foreach ([
            'web_radio_allowed' => 'setAllowWebradio',
            'discord_allowed' => 'setAllowDiscord',
            'stream_allowed' => 'setAllowStream',
            'api_allowed' => 'setAllowApi',
            'plugins_allowed' => 'setAllowPlugins',
        ] as $field => $setter) {
            $limits->{$setter}($request->request->has($field));
        }

        $permissions = array_map(static fn (\App\Module\Musicbot\Domain\Enum\MusicbotPermission $p): string => $p->value, \App\Module\Musicbot\Domain\Enum\MusicbotPermission::customerDefaults());
        $permissions = array_values(array_filter($permissions, static fn (string $permission): bool => !in_array($permission, [\App\Module\Musicbot\Domain\Enum\MusicbotPermission::YoutubeManage->value, \App\Module\Musicbot\Domain\Enum\MusicbotPermission::TeamspeakCommandsManage->value, \App\Module\Musicbot\Domain\Enum\MusicbotPermission::AutoDjManage->value], true)));
        foreach ([
            'youtube_allowed' => \App\Module\Musicbot\Domain\Enum\MusicbotPermission::YoutubeManage->value,
            'teamspeak_commands_allowed' => \App\Module\Musicbot\Domain\Enum\MusicbotPermission::TeamspeakCommandsManage->value,
            'autodj_allowed' => \App\Module\Musicbot\Domain\Enum\MusicbotPermission::AutoDjManage->value,
        ] as $field => $permission) {
            if ($request->request->has($field)) {
                $permissions[] = $permission;
            }
        }
        $limits->setGrantedPermissions($permissions);
        if (!$request->request->has('playlists_allowed')) {
            $limits->setMaxPlaylists(0);
        }

        $this->entityManager->flush();
        $this->auditLogger->log($actor, 'musicbot.admin_limits_updated', ['customer_id' => $customer->getId()]);
        $this->flash($request, 'success', 'Musicbot-Kundenlimits wurden gespeichert.');

        return new Response('', Response::HTTP_FOUND, ['Location' => '/admin/musicbots/limits']);
    }

    #[Route(path: '/plugins', name: 'admin_musicbot_plugins', methods: ['GET'])]
    public function plugins(Request $request): Response
    {
        $this->requireAdmin($request);

        return new Response($this->twig->render('admin/musicbot/plugins.html.twig', [
            'activeNav' => 'musicbots',
            'manifests' => $this->pluginRegistryService->listManifests(),
            'plugins' => $this->pluginRepository->findBy([], ['identifier' => 'ASC']),
        ]));
    }

    #[Route(path: '/teamspeak-backend', name: 'admin_musicbot_teamspeak_backend', methods: ['GET'])]
    public function teamspeakBackend(Request $request): Response
    {
        $this->requireAdmin($request);
        $node = $this->resolveTeamspeakBackendNode($request);
        $config = $node instanceof Agent ? $this->teamspeakBackendConfigRepository->findOneByNode($node) : null;

        return new Response($this->twig->render('admin/musicbot/teamspeak_backend.html.twig', [
            'activeNav' => 'musicbots',
            'nodes' => $this->agentRepository->findBy([], ['name' => 'ASC']),
            'selectedNode' => $node,
            'config' => $config,
            'form' => $this->buildTeamspeakBackendForm($config, $node),
            'statuses' => MusicbotTeamspeakBackendStatus::cases(),
        ]));
    }

    #[Route(path: '/teamspeak-backend/save', name: 'admin_musicbot_teamspeak_backend_save', methods: ['POST'])]
    public function saveTeamspeakBackend(Request $request): Response
    {
        $actor = $this->requireAdmin($request);
        $node = $this->requireTeamspeakBackendNode($request);
        $config = $this->teamspeakBackendConfigRepository->findOneByNode($node) ?? new MusicbotTeamspeakBackendConfig($node);
        $this->applyTeamspeakBackendRequest($config, $request);
        $this->entityManager->persist($config);
        $this->entityManager->flush();
        $this->auditLogger->log($actor, 'musicbot.teamspeak_backend_saved', ['node_id' => $node->getId()]);
        $this->flash($request, 'success', 'TeamSpeak Client Backend Konfiguration wurde gespeichert.');

        return new Response('', Response::HTTP_FOUND, ['Location' => '/admin/musicbots/teamspeak-backend?node_id='.$node->getId()]);
    }

    #[Route(path: '/teamspeak-backend/{action}', name: 'admin_musicbot_teamspeak_backend_action', requirements: ['action' => 'install|validate|status|test_connection|repair|install_official_client|install_sdk_client|install_dependencies'], methods: ['POST'])]
    public function teamspeakBackendAction(Request $request, string $action): Response
    {
        $actor = $this->requireAdmin($request);
        $node = $this->requireTeamspeakBackendNode($request);
        $config = $this->teamspeakBackendConfigRepository->findOneByNode($node);
        if (!$config instanceof MusicbotTeamspeakBackendConfig) {
            $this->flash($request, 'error', 'Bitte TeamSpeak Client Backend Konfiguration zuerst speichern.');
            return new Response('', Response::HTTP_FOUND, ['Location' => '/admin/musicbots/teamspeak-backend?node_id='.$node->getId()]);
        }

        if (in_array($action, ['install_official_client', 'install_sdk_client'], true) && !$request->request->getBoolean('accepted_license_confirmation')) {
            $this->flash($request, 'error', 'Bitte Lizenz-/Nutzungsbestätigung bestätigen.');
            return new Response('', Response::HTTP_FOUND, ['Location' => '/admin/musicbots/teamspeak-backend?node_id='.$node->getId()]);
        }

        if ($action === 'install_dependencies' && !$request->request->getBoolean('install_dependencies')) {
            $this->flash($request, 'error', 'Bitte Bestätigung für die Abhängigkeitsinstallation setzen (install_dependencies=true).');
            return new Response('', Response::HTTP_FOUND, ['Location' => '/admin/musicbots/teamspeak-backend?node_id='.$node->getId()]);
        }

        $missing = match ($action) {
            'install_official_client' => $this->missingOfficialClientRequiredFields($config),
            'install_sdk_client' => $this->missingSDKClientRequiredFields($config),
            'install_dependencies' => [],
            default => $this->missingTeamspeakBackendRequiredFields($config),
        };
        if ($missing !== []) {
            $this->flash($request, 'error', 'Fehlende Pflichtfelder: '.implode(', ', $missing));
            return new Response('', Response::HTTP_FOUND, ['Location' => '/admin/musicbots/teamspeak-backend?node_id='.$node->getId()]);
        }

        $jobType = 'musicbot.teamspeak_backend.'.($action === 'install' ? 'install' : $action);
        $payload = match ($action) {
            'install_official_client' => $config->toOfficialClientAgentPayload((string) $actor->getId()),
            'install_sdk_client' => $config->toSDKClientAgentPayload((string) $actor->getId()),
            'install_dependencies' => ['install_dependencies' => true],
            default => $config->toAgentPayload(),
        };
        $payload += [
            'node_id' => $node->getId(),
            'requested_by' => (string) $actor->getId(),
            'dry_run' => false,
        ];
        unset($payload['server_password'], $payload['channel_password'], $payload['bot_token']);

        $job = $this->jobDispatcher->dispatch($node, $jobType, $payload);
        $config->setLastCheckedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->auditLogger->log($actor, 'musicbot.teamspeak_backend_job_queued', ['node_id' => $node->getId(), 'job_id' => $job->getId(), 'job_type' => $jobType]);
        $message = match ($action) {
            'install_official_client' => sprintf('Offizieller TeamSpeak Client Installationsjob wurde gestartet. Job-ID: %s', $job->getId()),
            'install_sdk_client' => sprintf('TeamSpeak SDK Installationsjob wurde gestartet. Job-ID: %s', $job->getId()),
            'install_dependencies' => sprintf('Systemabhängigkeiten-Installationsjob wurde gestartet. Job-ID: %s', $job->getId()),
            'install' => sprintf('TeamSpeak Client Backend Installationsprüfung wurde gestartet. Job-ID: %s', $job->getId()),
            default => sprintf('TeamSpeak Client Backend Prüfung wurde gestartet. Job-ID: %s', $job->getId()),
        };
        $this->flash($request, 'success', $message);

        return new Response('', Response::HTTP_FOUND, ['Location' => '/admin/musicbots/teamspeak-backend?node_id='.$node->getId().'&last_job_id='.$job->getId()]);
    }

    #[Route(path: '/new', name: 'admin_musicbot_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin($request);

        return new Response($this->twig->render('admin/musicbot/new.html.twig', [
            'activeNav' => 'musicbots',
            'customers' => $this->userRepository->findCustomers(),
            'nodes' => $this->agentRepository->findBy([], ['name' => 'ASC']),
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '', name: 'admin_musicbot_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $this->requireAdmin($request);
        $form = $this->parseCreatePayload($request);
        if ($form['errors'] !== []) {
            return $this->renderCreateForm($form, Response::HTTP_BAD_REQUEST);
        }

        /** @var User $customer */
        $customer = $form['customer'];
        /** @var Agent $node */
        $node = $form['node'];
        try {
            $this->quotaService->assertCanCreateMusicbot($customer);
            if ($form['teamspeak_enabled']) {
                $this->quotaService->assertCanManageTeamspeakConnection($customer);
            }
            if ($form['discord_enabled']) {
                $this->quotaService->assertCanManageDiscordConnection($customer);
            }
        } catch (\App\Module\Musicbot\Domain\Exception\MusicbotQuotaExceededException $exception) {
            $form['errors'][] = $exception->getMessage();
            return $this->renderCreateForm($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $serviceName = $this->buildServiceName($form['name']);
        $installPath = sprintf('/var/lib/easywi/musicbot/%s', $serviceName);
        $instance = new MusicbotInstance(
            $customer,
            $node,
            $form['name'],
            $serviceName,
            $installPath,
            $form['cpu_limit'],
            $form['ram_limit'],
            $form['disk_limit'],
        );
        $instance->setStatus(MusicbotInstanceStatus::Provisioning);
        $this->entityManager->persist($instance);

        if ($form['teamspeak_enabled']) {
            $this->entityManager->persist(new MusicbotConnection($instance, MusicbotPlatform::Teamspeak, $form['teamspeak_config'], $this->secretConfigService->encrypt($form['teamspeak_secret_config'])));
        }
        if ($form['discord_enabled']) {
            $this->entityManager->persist(new MusicbotConnection($instance, MusicbotPlatform::Discord));
        }

        $this->entityManager->flush();
        $job = $this->queueJob('musicbot.install', $instance, [
            'connections' => [
                'teamspeak' => $form['teamspeak_enabled'],
                'discord' => $form['discord_enabled'],
            ],
        ]);
        $this->runtimeEventService->record($instance, 'instance.created', 'info', 'Musicbot instance created');
        $this->auditLogger->log($actor, 'musicbot.instance_created', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'node_id' => $node->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/admin/musicbots/%d', $instance->getId())]);
    }

    #[Route(path: '/{id}', name: 'admin_musicbot_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $instance = $this->findInstance($id);

        $runtimePayload = $this->runtimeStatusNormalizer->normalizePayload($this->sanitizeForTemplate($instance->getRuntimePayload() ?? []));
        $playbackStatus = $runtimePayload['playback_status'] ?? $this->runtimeStatusNormalizer->buildPlaybackStatus($runtimePayload);
        if ((string) ($_ENV['MUSICBOT_DEBUG_STATUS_FLOW'] ?? $_SERVER['MUSICBOT_DEBUG_STATUS_FLOW'] ?? getenv('MUSICBOT_DEBUG_STATUS_FLOW') ?: '') === '1') {
            error_log('[musicbot-status-flow] '.json_encode(['stage' => 'admin.controller.payload.passed_to_twig', 'instance_id' => $instance->getId()] + MusicbotPayloadLogSummarizer::summarizeRuntimePayload($runtimePayload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }

        return new Response($this->twig->render('admin/musicbot/show.html.twig', [
            'activeNav' => 'musicbots',
            'instance' => $instance,
            'connections' => $this->buildConnectionRows($this->connectionRepository->findBy(['musicbotInstance' => $instance], ['id' => 'ASC'])),
            'rawConnections' => $this->connectionRepository->findBy(['musicbotInstance' => $instance], ['id' => 'ASC']),
            'queueSummary' => $this->buildQueueSummary($instance),
            'runtimePayload' => $runtimePayload,
            'playbackStatus' => $playbackStatus,
            'lastError' => $this->resolveLastError($runtimePayload),
            'logs' => $this->runtimeEventService->latestForInstance($instance, 50),
            'errors' => $this->runtimeEventService->errorsForInstance($instance, 10),
            'teamspeakProfiles' => MusicbotTeamspeakProfile::cases(),
            'actions' => self::ACTIONS,
        ]));
    }

    #[Route(path: '/{id}/action', name: 'admin_musicbot_action', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function action(Request $request, int $id): Response
    {
        $actor = $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $action = strtolower(trim((string) $request->request->get('action', '')));
        if (!in_array($action, self::ACTIONS, true)) {
            return new Response('Unsupported action.', Response::HTTP_BAD_REQUEST);
        }

        $job = $this->queueJob($this->jobTypeForAction($action), $instance, ['action' => $action]);
        $this->applyPendingStatus($instance, $action);
        $this->runtimeEventService->record($instance, 'instance.'.($action === 'restart' ? 'restarted' : $action), 'info', $action);
        $this->auditLogger->log($actor, sprintf('musicbot.instance_%s', $action), [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/admin/musicbots/%d', $instance->getId())]);
    }


    #[Route(path: '/{id}/connections/teamspeak', name: 'admin_musicbot_connection_teamspeak_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateTeamspeakConnection(Request $request, int $id): Response
    {
        $actor = $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $connection = $this->findOrCreateConnection($instance, MusicbotPlatform::Teamspeak);
        $errors = [];
        $connection->setEnabled($request->request->getBoolean('enabled'));
        $connection->setConnectionConfig($this->parseTeamspeakConfig($request, $errors));
        $connection->setSecretConfig(
            $this->secretConfigService->mergeSecretUpdates($connection->getSecretConfig(), $this->parseTeamspeakSecretConfig($request)),
        );
        $this->entityManager->persist($connection);
        $this->runtimeEventService->record($instance, 'connector.status.changed', 'info', 'TeamSpeak connection updated', ['platform' => 'teamspeak', 'profile' => $connection->getTeamspeakProfile()->value]);
        $this->auditLogger->log($actor, 'musicbot.admin_connection_teamspeak_updated', ['instance_id' => $id, 'connection_id' => $connection->getId(), 'enabled' => $connection->isEnabled(), 'profile' => $connection->getTeamspeakProfile()->value]);
        $this->entityManager->flush();
        $this->dispatchConfigApplyJob($instance);
        $this->flash($request, 'success', 'TeamSpeak-Verbindung wurde gespeichert.');

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/admin/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/connections/discord', name: 'admin_musicbot_connection_discord_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateDiscordConnection(Request $request, int $id): Response
    {
        $actor = $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $connection = $this->findOrCreateConnection($instance, MusicbotPlatform::Discord);
        $connection->setEnabled($request->request->getBoolean('enabled'));
        $config = $connection->getConnectionConfig();
        $config['application_id'] = trim((string) $request->request->get('application_id', $config['application_id'] ?? ''));
        $config['guild_id'] = trim((string) $request->request->get('guild_id', $config['guild_id'] ?? ''));
        $config['voice_channel_id'] = trim((string) $request->request->get('voice_channel_id', $config['voice_channel_id'] ?? ''));
        $config['text_channel_id'] = trim((string) $request->request->get('text_channel_id', $config['text_channel_id'] ?? ''));
        $config['command_mode'] = in_array((string) $request->request->get('command_mode', $config['command_mode'] ?? 'placeholder'), ['placeholder', 'slash'], true) ? (string) $request->request->get('command_mode', $config['command_mode'] ?? 'placeholder') : 'placeholder';
        $config['slash_commands_enabled'] = $request->request->getBoolean('slash_commands_enabled');
        $config['reconnect_policy'] = in_array((string) $request->request->get('reconnect_policy', $config['reconnect_policy'] ?? 'manual'), ['manual', 'exponential_backoff'], true) ? (string) $request->request->get('reconnect_policy', $config['reconnect_policy'] ?? 'manual') : 'manual';
        $config['capability_status'] = 'voice_backend_required';
        $connection->setConnectionConfig($config);
        $connection->setSecretConfig(
            $this->secretConfigService->mergeSecretUpdates($connection->getSecretConfig(), ['bot_token' => trim((string) $request->request->get('bot_token', ''))]),
        );
        $this->entityManager->persist($connection);
        $this->runtimeEventService->record($instance, 'connector.status.changed', 'info', 'Discord connection updated', ['platform' => 'discord']);
        $this->auditLogger->log($actor, 'musicbot.admin_connection_discord_updated', ['instance_id' => $id, 'connection_id' => $connection->getId(), 'enabled' => $connection->isEnabled()]);
        $this->entityManager->flush();
        $this->dispatchConfigApplyJob($instance);
        $this->flash($request, 'success', 'Discord-Verbindung wurde gespeichert.');

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/admin/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/connections/{platform}/test', name: 'admin_musicbot_connection_test', requirements: ['id' => '\d+', 'platform' => 'teamspeak|discord'], methods: ['POST'])]
    public function testConnection(Request $request, int $id, string $platform): Response
    {
        $actor = $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $job = $this->queueJob('musicbot.connection.test', $instance, ['platform' => $platform]);
        $this->auditLogger->log($actor, 'musicbot.admin_connection_test', ['instance_id' => $id, 'platform' => $platform, 'job_id' => $job->getId()]);
        $this->entityManager->flush();
        $this->flash($request, 'success', sprintf('Connection-Test für %s wurde gestartet.', $platform));

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/admin/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/delete', name: 'admin_musicbot_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $job = $this->queueJob('musicbot.uninstall', $instance, ['delete_data' => true]);
        $this->runtimeEventService->record($instance, 'instance.deleted', 'info', 'Musicbot instance deleted');
        $this->auditLogger->log($actor, 'musicbot.instance_deleted', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->remove($instance);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_FOUND, ['Location' => '/admin/musicbots']);
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException($this->translator->trans('error_forbidden'));
        }

        return $actor;
    }

    private function resolveTeamspeakBackendNode(Request $request): ?Agent
    {
        $nodeId = (string) $request->query->get('node_id', $request->request->get('node_id', ''));
        if ($nodeId === '') {
            $nodes = $this->agentRepository->findBy([], ['name' => 'ASC'], 1);
            return $nodes[0] ?? null;
        }
        $node = $this->agentRepository->find($nodeId);
        return $node instanceof Agent ? $node : null;
    }

    private function requireTeamspeakBackendNode(Request $request): Agent
    {
        $node = $this->resolveTeamspeakBackendNode($request);
        if (!$node instanceof Agent) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Agent node not found.');
        }

        return $node;
    }

    private function applyTeamspeakBackendRequest(MusicbotTeamspeakBackendConfig $config, Request $request): void
    {
        $backendType = (string) $request->request->get('backend_type', 'client_library');
        if (!in_array($backendType, ['client_library', 'native_sdk', 'external_client_bridge'], true)) {
            $backendType = 'client_library';
        }

        $config->setBackendType($backendType);
        $config->setBackendPath(trim((string) $request->request->get('backend_path', '')));
        $config->setBinaryPath(trim((string) $request->request->get('binary_path', '')));
        $config->setLibraryPath(trim((string) $request->request->get('library_path', '')));
        $config->setOpusLibraryPath(trim((string) $request->request->get('opus_library_path', '')) ?: null);
        $config->setIdentityPath(trim((string) $request->request->get('identity_path', '')) ?: null);
        $config->setInstallPath(trim((string) $request->request->get('install_path', '/opt/easywi/musicbot/teamspeak-client/')));
        $config->setVersion(trim((string) $request->request->get('version', '')) ?: null);
        $config->setChecksum(trim((string) $request->request->get('checksum', '')) ?: null);
        $config->setAutoInstallEnabled($request->request->getBoolean('auto_install_enabled'));
        $config->setOfficialClientInstallEnabled($request->request->getBoolean('official_client_install_enabled'));
        $config->setOfficialClientVersion(trim((string) $request->request->get('official_client_version', '3.6.2')) ?: '3.6.2');
        $config->setOfficialClientDownloadUrl(trim((string) $request->request->get('official_client_download_url', 'https://files.teamspeak-services.com/releases/client/3.6.2/TeamSpeak3-Client-linux_amd64-3.6.2.run')));
        $config->setOfficialClientExpectedSha256(trim((string) $request->request->get('official_client_expected_sha256', '')) ?: null);
        $config->setOfficialClientInstallPath(trim((string) $request->request->get('official_client_install_path', '/opt/easywi/musicbot/teamspeak-client/official-client/')));
        $config->setSdkClientInstallEnabled($request->request->getBoolean('sdk_client_install_enabled'));
        $config->setSdkClientVersion(trim((string) $request->request->get('sdk_client_version', '3.5.2')) ?: '3.5.2');
        $config->setSdkClientDownloadUrl(trim((string) $request->request->get('sdk_client_download_url', 'https://files.teamspeak-services.com/releases/sdk/3.5.2/teamspeak-sdk-3.5.2.tar.gz')));
        $config->setSdkClientExpectedSha256(trim((string) $request->request->get('sdk_client_expected_sha256', '')) ?: null);
        $config->setSdkClientInstallPath(trim((string) $request->request->get('sdk_client_install_path', '/opt/easywi/musicbot/teamspeak-client/sdk/')));
        $config->setBridgePath(trim((string) $request->request->get('bridge_path', '/usr/local/bin/easywi-teamspeak-bridge')) ?: '/usr/local/bin/easywi-teamspeak-bridge');
        $config->setOfficialClientBinaryPath(trim((string) $request->request->get('official_client_binary_path', '')) ?: null);
        $config->setOfficialClientRunscriptPath(trim((string) $request->request->get('official_client_runscript_path', '')) ?: null);
        $config->setAudioBackend(trim((string) $request->request->get('audio_backend', 'pulseaudio_virtual_source')) ?: 'pulseaudio_virtual_source');
    }

    /** @return string[] */
    private function missingTeamspeakBackendRequiredFields(MusicbotTeamspeakBackendConfig $config): array
    {
        $missing = [];

        if (trim($config->getBackendType()) === '') {
            $missing[] = 'backend_type';
        }
        if (trim($config->getInstallPath()) === '') {
            $missing[] = 'install_path';
        }

        if ($config->getBackendType() === 'external_client_bridge') {
            if (trim($config->getBridgePath()) === '') {
                $missing[] = 'bridge_path';
            }
        } else {
            if (trim($config->getBackendPath()) === '') {
                $missing[] = 'backend_path';
            }
            if (trim($config->getBinaryPath()) === '') {
                $missing[] = 'binary_path';
            }
            if (trim($config->getLibraryPath()) === '') {
                $missing[] = 'library_path';
            }
        }

        return $missing;
    }

    /** @return string[] */
    private function missingOfficialClientRequiredFields(MusicbotTeamspeakBackendConfig $config): array
    {
        $missing = [];
        foreach ([
            'official_client_version' => $config->getOfficialClientVersion(),
            'official_client_download_url' => $config->getOfficialClientDownloadUrl(),
            'official_client_install_path' => $config->getOfficialClientInstallPath(),
        ] as $key => $value) {
            if (trim((string) $value) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /** @return string[] */
    private function missingSDKClientRequiredFields(MusicbotTeamspeakBackendConfig $config): array
    {
        $missing = [];
        foreach ([
            'sdk_client_version' => $config->getSdkClientVersion(),
            'sdk_client_download_url' => $config->getSdkClientDownloadUrl(),
            'sdk_client_install_path' => $config->getSdkClientInstallPath(),
        ] as $key => $value) {
            if (trim((string) $value) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /** @return array<string, mixed> */
    private function buildTeamspeakBackendForm(?MusicbotTeamspeakBackendConfig $config, ?Agent $node): array
    {
        return [
            'node_id' => $node?->getId() ?? '',
            'backend_type' => $config?->getBackendType() ?? 'client_library',
            'backend_path' => $config?->getBackendPath() ?? '/usr/local/bin/easywi-teamspeak-client',
            'binary_path' => $config?->getBinaryPath() ?? '/usr/local/bin/easywi-teamspeak-client',
            'library_path' => $config?->getLibraryPath() ?? '/opt/easywi/musicbot/teamspeak-client/libts3client.so',
            'opus_library_path' => $config?->getOpusLibraryPath() ?? '/opt/easywi/musicbot/teamspeak-client/libopus.so',
            'identity_path' => $config?->getIdentityPath() ?? '',
            'install_path' => $config?->getInstallPath() ?? '/opt/easywi/musicbot/teamspeak-client/',
            'version' => $config?->getVersion() ?? '',
            'checksum' => $config?->getChecksum() ?? '',
            'auto_install_enabled' => $config?->isAutoInstallEnabled() ?? false,
            'status' => $config?->getStatus()->value ?? MusicbotTeamspeakBackendStatus::NotConfigured->value,
            'last_error' => $this->sanitizeTextForTemplate($config?->getLastError()),
            'last_checked_at' => $config?->getLastCheckedAt(),
            'official_client_install_enabled' => $config?->isOfficialClientInstallEnabled() ?? false,
            'official_client_version' => $config?->getOfficialClientVersion() ?? '3.6.2',
            'official_client_download_url' => $config?->getOfficialClientDownloadUrl() ?? 'https://files.teamspeak-services.com/releases/client/3.6.2/TeamSpeak3-Client-linux_amd64-3.6.2.run',
            'official_client_expected_sha256' => $config?->getOfficialClientExpectedSha256() ?? '',
            'official_client_install_path' => $config?->getOfficialClientInstallPath() ?? '/opt/easywi/musicbot/teamspeak-client/official-client/',
            'official_client_status' => $config?->getOfficialClientStatus() ?? 'official_client_not_installed',
            'official_client_last_error' => $this->sanitizeTextForTemplate($config?->getOfficialClientLastError()),
            'official_client_last_installed_at' => $config?->getOfficialClientLastInstalledAt(),
            'sdk_client_install_enabled' => $config?->isSdkClientInstallEnabled() ?? false,
            'sdk_client_version' => $config?->getSdkClientVersion() ?? '3.5.2',
            'sdk_client_download_url' => $config?->getSdkClientDownloadUrl() ?? 'https://files.teamspeak-services.com/releases/sdk/3.5.2/teamspeak-sdk-3.5.2.tar.gz',
            'sdk_client_expected_sha256' => $config?->getSdkClientExpectedSha256() ?? '',
            'sdk_client_install_path' => $config?->getSdkClientInstallPath() ?? '/opt/easywi/musicbot/teamspeak-client/sdk/',
            'sdk_client_status' => $config?->getSdkClientStatus() ?? 'sdk_client_not_installed',
            'sdk_client_last_error' => $this->sanitizeTextForTemplate($config?->getSdkClientLastError()),
            'sdk_client_last_installed_at' => $config?->getSdkClientLastInstalledAt(),
            'bridge_path' => $config?->getBridgePath() ?? '/usr/local/bin/easywi-teamspeak-bridge',
            'official_client_binary_path' => $config?->getOfficialClientBinaryPath() ?? '',
            'official_client_runscript_path' => $config?->getOfficialClientRunscriptPath() ?? '',
            'audio_backend' => $config?->getAudioBackend() ?? 'pulseaudio_virtual_source',
        ];
    }

    private function findInstance(int $id): MusicbotInstance
    {
        $instance = $this->instanceRepository->find($id);
        if (!$instance instanceof MusicbotInstance) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException($this->translator->trans('error_not_found'));
        }

        return $instance;
    }

    /** @return array<string, mixed> */
    private function parseCreatePayload(Request $request): array
    {
        $customerId = $request->request->get('customer_id');
        $nodeId = (string) $request->request->get('node_id', '');
        $name = trim((string) $request->request->get('name', ''));
        $errors = [];
        $customer = is_numeric($customerId) ? $this->userRepository->find((int) $customerId) : null;
        if (!$customer instanceof User || $customer->getType() !== UserType::Customer) {
            $errors[] = 'Customer is required.';
        }
        $node = $nodeId !== '' ? $this->agentRepository->find($nodeId) : null;
        if (!$node instanceof Agent) {
            $errors[] = 'Node/Agent is required.';
        }
        if ($name === '') {
            $errors[] = 'Name is required.';
        }

        return [
            'customer' => $customer,
            'node' => $node,
            'name' => $name,
            'cpu_limit' => $this->parsePositiveInt($request->request->get('cpu_limit'), 'CPU limit', $errors),
            'ram_limit' => $this->parsePositiveInt($request->request->get('ram_limit'), 'RAM limit', $errors),
            'disk_limit' => $this->parsePositiveInt($request->request->get('disk_limit'), 'Disk limit', $errors),
            'teamspeak_enabled' => $request->request->getBoolean('teamspeak_enabled'),
            'teamspeak_profile' => (string) $request->request->get('teamspeak_profile', MusicbotTeamspeakProfile::Ts3->value),
            'teamspeak_backend_type' => (string) $request->request->get('teamspeak_backend_type', 'placeholder'),
            'teamspeak_backend_path' => trim((string) $request->request->get('teamspeak_backend_path', '')),
            'teamspeak_identity_path' => trim((string) $request->request->get('teamspeak_identity_path', '')),
            'teamspeak_command_prefix' => trim((string) $request->request->get('teamspeak_command_prefix', '!')),
            'teamspeak_commands_enabled' => $request->request->getBoolean('teamspeak_commands_enabled'),
            'teamspeak_events_enabled' => $request->request->getBoolean('teamspeak_events_enabled'),
            'teamspeak_allowed_server_groups' => trim((string) $request->request->get('teamspeak_allowed_server_groups', '')),
            'teamspeak_dj_server_groups' => trim((string) $request->request->get('teamspeak_dj_server_groups', '')),
            'teamspeak_admin_server_groups' => trim((string) $request->request->get('teamspeak_admin_server_groups', '')),
            'teamspeak_host' => trim((string) $request->request->get('teamspeak_host', '')),
            'teamspeak_port' => max(1, (int) $request->request->get('teamspeak_port', 9987)),
            'teamspeak_nickname' => trim((string) $request->request->get('teamspeak_nickname', '')),
            'teamspeak_channel_id' => trim((string) $request->request->get('teamspeak_channel_id', '')),
            'teamspeak_server_password' => (string) $request->request->get('teamspeak_server_password', ''),
            'teamspeak_channel_password' => (string) $request->request->get('teamspeak_channel_password', ''),
            'teamspeak_config' => $this->parseTeamspeakConfig($request, $errors),
            'teamspeak_secret_config' => $this->parseTeamspeakSecretConfig($request),
            'discord_enabled' => $request->request->getBoolean('discord_enabled'),
            'errors' => $errors,
        ];
    }


    /** @return array<string, mixed> */
    private function parseTeamspeakConfig(Request $request, array &$errors): array
    {
        $profile = MusicbotTeamspeakProfile::tryFrom((string) $request->request->get('teamspeak_profile', MusicbotTeamspeakProfile::Ts3->value));
        if (!$profile instanceof MusicbotTeamspeakProfile) {
            $errors[] = 'TeamSpeak profile must be ts3 or ts6.';
            $profile = MusicbotTeamspeakProfile::Ts3;
        }

        return [
            'profile' => $profile->value,
            'backend' => 'ts3_client_compatible',
            'backend_type' => $this->normalizeTeamspeakBackendType((string) $request->request->get('teamspeak_backend_type', 'placeholder')),
            'backend_path' => trim((string) $request->request->get('teamspeak_backend_path', '')),
            'identity_path' => trim((string) $request->request->get('teamspeak_identity_path', '')),
            'library_path' => trim((string) $request->request->get('teamspeak_library_path', '')),
            'opus_library_path' => trim((string) $request->request->get('teamspeak_opus_library_path', '')),
            'binary_path' => trim((string) $request->request->get('teamspeak_binary_path', '')),
            'command_prefix' => trim((string) $request->request->get('teamspeak_command_prefix', '!')) ?: '!',
            'commands_enabled' => $request->request->getBoolean('teamspeak_commands_enabled'),
            'events_enabled' => $request->request->getBoolean('teamspeak_events_enabled'),
            'allowed_server_groups' => $this->parseCsvList((string) $request->request->get('teamspeak_allowed_server_groups', '')),
            'dj_server_groups' => $this->parseCsvList((string) $request->request->get('teamspeak_dj_server_groups', '')),
            'admin_server_groups' => $this->parseCsvList((string) $request->request->get('teamspeak_admin_server_groups', '')),
            'host' => trim((string) $request->request->get('teamspeak_host', '')),
            'port' => max(1, (int) $request->request->get('teamspeak_port', 9987)),
            'nickname' => trim((string) $request->request->get('teamspeak_nickname', '')),
            'channel_id' => trim((string) $request->request->get('teamspeak_channel_id', '')),
        ];
    }



    /** @return list<string> */
    private function parseCsvList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
    }

    private function normalizeTeamspeakBackendType(string $backendType): string
    {
        return in_array($backendType, ['placeholder', 'client_library', 'native_sdk', 'external_client_bridge', 'disabled'], true) ? $backendType : 'placeholder';
    }

    /** @return array<string, mixed> */
    private function parseTeamspeakSecretConfig(Request $request): array
    {
        return [
            'server_password' => (string) $request->request->get('teamspeak_server_password', ''),
            'channel_password' => (string) $request->request->get('teamspeak_channel_password', ''),
        ];
    }

    private function parsePositiveInt(mixed $value, string $label, array &$errors): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_numeric($value) || (int) $value < 0) {
            $errors[] = sprintf('%s must be a positive number.', $label);
            return 0;
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $form */
    private function renderCreateForm(array $form, int $status): Response
    {
        return new Response($this->twig->render('admin/musicbot/new.html.twig', [
            'activeNav' => 'musicbots',
            'customers' => $this->userRepository->findCustomers(),
            'nodes' => $this->agentRepository->findBy([], ['name' => 'ASC']),
            'form' => $this->buildFormContext($form),
        ]), $status);
    }


    /** @param MusicbotInstance[] $instances @return array<int, array<string, mixed>> */
    private function buildIndexRows(array $instances): array
    {
        return array_map(function (MusicbotInstance $instance): array {
            $connections = $this->connectionRepository->findBy(['musicbotInstance' => $instance], ['id' => 'ASC']);
            return [
                'instance' => $instance,
                'teamspeakActive' => $this->isPlatformEnabled($connections, MusicbotPlatform::Teamspeak),
                'discordActive' => $this->isPlatformEnabled($connections, MusicbotPlatform::Discord),
            ];
        }, $instances);
    }

    /** @param MusicbotConnection[] $connections @return array<int, array<string, mixed>> */
    private function buildConnectionRows(array $connections): array
    {
        return array_map(function (MusicbotConnection $connection): array {
            $config = $connection->getConnectionConfig();

            return [
                'entity' => $connection,
                'config' => $config,
                'secrets' => $this->secretConfigService->normalizeForApi($connection->getSecretConfig()),
                'capability_status' => (string) ($config['capability_status'] ?? ($connection->getPlatform() === MusicbotPlatform::Teamspeak ? 'client_backend_required' : 'voice_backend_required')),
                'slash_commands_status' => $connection->getPlatform() === MusicbotPlatform::Discord ? 'placeholder' : null,
                'last_error' => $this->sanitizeTextForTemplate($connection->getLastError()),
            ];
        }, $connections);
    }

    /** @return array<string, mixed> */
    private function buildQueueSummary(MusicbotInstance $instance): array
    {
        $queue = $this->queueItemRepository->findQueueForInstanceOrdered($instance);
        $current = null;
        foreach ($queue as $item) { if (in_array($item->getStatus(), ['playing', 'current'], true)) { $current = $item->getTrack(); break; } }
        return ['length' => count($queue), 'currentTrack' => $current, 'items' => array_slice($queue, 0, 10)];
    }

    /** @param array<string, mixed> $runtimePayload */
    private function resolveLastError(array $runtimePayload): ?string
    {
        return $this->runtimeStatusNormalizer->resolveActiveLastError($runtimePayload);
    }

    /** @param MusicbotConnection[] $connections */
    private function isPlatformEnabled(array $connections, MusicbotPlatform $platform): bool
    {
        foreach ($connections as $connection) { if ($connection->getPlatform() === $platform && $connection->isEnabled()) { return true; } }
        return false;
    }

    private function findOrCreateConnection(MusicbotInstance $instance, MusicbotPlatform $platform): MusicbotConnection
    {
        foreach ($this->connectionRepository->findBy(['musicbotInstance' => $instance], ['id' => 'ASC']) as $connection) { if ($connection->getPlatform() === $platform) { return $connection; } }
        $connection = new MusicbotConnection($instance, $platform);
        $this->entityManager->persist($connection);
        return $connection;
    }


    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeForTemplate(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_string($value) ? $this->sanitizeTextForTemplate($value) : $value;
        }

        $sanitized = [];
        foreach ($value as $key => $item) {
            $keyString = is_string($key) ? strtolower($key) : (string) $key;
            if (str_contains($keyString, 'token') || str_contains($keyString, 'password') || str_contains($keyString, 'secret') || str_contains($keyString, 'auth')) {
                $sanitized[$key] = $item === null || $item === '' ? $item : '[redacted]';
                continue;
            }
            $sanitized[$key] = $this->sanitizeForTemplate($item);
        }

        return $sanitized;
    }


    private function sanitizeTextForTemplate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return preg_replace('/(token|password|secret|authorization)([\s_:\-=]+)([^\s,;]+)/i', '$1$2[redacted]', $value) ?? $value;
    }

    private function flash(Request $request, string $type, string $message): void
    {
        if ($request->hasSession()) { $request->getSession()->getFlashBag()->add($type, $message); }
    }

    /** @param MusicbotInstance[] $instances @return array<string, int> */
    private function buildSummary(array $instances): array
    {
        $summary = ['total' => count($instances), 'running' => 0, 'stopped' => 0, 'error' => 0, 'provisioning' => 0, 'nodes' => 0];
        $nodeIds = [];
        foreach ($instances as $instance) {
            $nodeIds[$instance->getNode()->getId()] = true;
            match ($instance->getStatus()) {
                MusicbotInstanceStatus::Running => $summary['running']++,
                MusicbotInstanceStatus::Stopped, MusicbotInstanceStatus::Installed => $summary['stopped']++,
                MusicbotInstanceStatus::Error => $summary['error']++,
                default => $summary['provisioning']++,
            };
        }

        $summary['nodes'] = count($nodeIds);

        return $summary;
    }


    /** @param array<string, mixed>|null $override @return array<string, mixed> */
    private function buildFormContext(?array $override = null): array
    {
        $data = [
            'customer' => null,
            'node' => null,
            'name' => '',
            'cpu_limit' => 100,
            'ram_limit' => 512,
            'disk_limit' => 1024,
            'teamspeak_enabled' => false,
            'teamspeak_profile' => MusicbotTeamspeakProfile::Ts3->value,
            'teamspeak_backend_type' => 'placeholder',
            'teamspeak_backend_path' => '',
            'teamspeak_identity_path' => '',
            'teamspeak_library_path' => '',
            'teamspeak_opus_library_path' => '',
            'teamspeak_binary_path' => '',
            'teamspeak_backend_types' => ['client_library' => 'Client Library', 'native_sdk' => 'Native SDK', 'disabled' => 'Disabled'],
            'teamspeak_command_prefix' => '!',
            'teamspeak_commands_enabled' => true,
            'teamspeak_events_enabled' => true,
            'teamspeak_allowed_server_groups' => '',
            'teamspeak_dj_server_groups' => '',
            'teamspeak_admin_server_groups' => '',
            'teamspeak_host' => '',
            'teamspeak_port' => 9987,
            'teamspeak_nickname' => '',
            'teamspeak_channel_id' => '',
            'teamspeak_server_password' => '',
            'teamspeak_channel_password' => '',
            'teamspeak_profiles' => MusicbotTeamspeakProfile::cases(),
            'discord_enabled' => false,
            'discord_text_channel_id' => '',
            'errors' => [],
        ];

        return $override !== null ? array_merge($data, $override) : $data;
    }

    private function buildServiceName(string $name): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $slug = trim($slug, '-') ?: 'musicbot';

        return sprintf('musicbot-%s-%s', substr($slug, 0, 32), bin2hex(random_bytes(3)));
    }

    #[Route(path: '/schedules', name: 'admin_musicbot_schedules', methods: ['GET'])]
    public function schedules(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requireAdmin($request);
        $filters = [];
        if ($request->query->has('customer_id') && is_numeric($request->query->get('customer_id'))) {
            $filters['customer_id'] = (int) $request->query->get('customer_id');
        }
        if ($request->query->has('instance_id') && is_numeric($request->query->get('instance_id'))) {
            $filters['instance_id'] = (int) $request->query->get('instance_id');
        }

        $schedules = $this->scheduleRepository->findForAdmin($filters);

        return new \Symfony\Component\HttpFoundation\JsonResponse([
            'data' => array_map(fn (\App\Module\Musicbot\Domain\Entity\MusicbotSchedule $s): array => array_merge($this->scheduleService->normalize($s), [
                'customer' => ['id' => $s->getCustomer()->getId(), 'email' => $s->getCustomer()->getEmail()],
            ]), $schedules),
            'total' => count($schedules),
        ]);
    }

    #[Route(path: '/{id}/schedules', name: 'admin_musicbot_instance_schedules', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function instanceSchedules(Request $request, int $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->instanceRepository->find($id);
        if (!$instance instanceof MusicbotInstance) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Not found.'], 404);
        }

        $schedules = $this->scheduleRepository->findByInstance($instance);

        return new \Symfony\Component\HttpFoundation\JsonResponse([
            'data' => array_map(fn (\App\Module\Musicbot\Domain\Entity\MusicbotSchedule $s): array => $this->scheduleService->normalize($s), $schedules),
        ]);
    }

    #[Route(path: '/{id}/autodj', name: 'admin_musicbot_instance_autodj', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function instanceAutoDj(Request $request, int $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->instanceRepository->find($id);
        if (!$instance instanceof MusicbotInstance) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Not found.'], 404);
        }

        $settings = $this->autoDjService->getOrCreateSettings($instance);

        return new \Symfony\Component\HttpFoundation\JsonResponse(['data' => $this->autoDjService->normalize($settings)]);
    }

    #[Route(path: '/{id}/stream', name: 'admin_musicbot_instance_stream', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function instanceStream(Request $request, int $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->instanceRepository->find($id);
        if (!$instance instanceof MusicbotInstance) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Not found.'], 404);
        }

        $settings = $this->streamService->getOrCreateSettings($instance);

        return new \Symfony\Component\HttpFoundation\JsonResponse(['data' => $this->streamService->normalize($settings)]);
    }

    #[Route(path: '/workflows', name: 'admin_musicbot_workflows', methods: ['GET'])]
    public function workflows(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requireAdmin($request);
        $filters = [];
        foreach (['customer_id', 'instance_id', 'trigger_type'] as $key) {
            if ($request->query->has($key)) {
                $filters[$key] = $request->query->get($key);
            }
        }
        if ($request->query->has('enabled')) {
            $filters['enabled'] = filter_var($request->query->get('enabled'), FILTER_VALIDATE_BOOLEAN);
        }

        $workflows = $this->workflowRepository->findForAdmin($filters);

        return new \Symfony\Component\HttpFoundation\JsonResponse([
            'data' => array_map(fn (MusicbotWorkflow $w): array => array_merge($this->workflowService->normalize($w), [
                'customer' => ['id' => $w->getCustomer()->getId(), 'email' => $w->getCustomer()->getEmail()],
            ]), $workflows),
            'total' => count($workflows),
        ]);
    }

    #[Route(path: '/{id}/workflows', name: 'admin_musicbot_instance_workflows', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function instanceWorkflows(Request $request, int $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->instanceRepository->find($id);
        if (!$instance instanceof MusicbotInstance) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Not found.'], 404);
        }

        $workflows = $this->workflowRepository->findByInstance($instance);

        return new \Symfony\Component\HttpFoundation\JsonResponse([
            'data' => array_map(fn (MusicbotWorkflow $w): array => $this->workflowService->normalize($w), $workflows),
        ]);
    }

    #[Route(path: '/workflows/{id}/disable', name: 'admin_musicbot_workflow_disable', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function disableWorkflow(Request $request, int $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $workflow = $this->workflowRepository->find($id);
        if (!$workflow instanceof MusicbotWorkflow) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Not found.'], 404);
        }

        $workflow->setEnabled(false);
        $this->entityManager->flush();
        $this->auditLogger->log($actor, 'musicbot.admin_workflow_disabled', ['workflow_id' => $workflow->getId(), 'customer_id' => $workflow->getCustomer()->getId()]);

        return new \Symfony\Component\HttpFoundation\JsonResponse(['data' => $this->workflowService->normalize($workflow)]);
    }

    private function dispatchConfigApplyJob(MusicbotInstance $instance): void
    {
        if (!in_array($instance->getStatus(), [MusicbotInstanceStatus::Running, MusicbotInstanceStatus::Stopped, MusicbotInstanceStatus::Installed], true)) {
            return;
        }

        $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.config.apply', $this->configApplyPayloadBuilder->build($instance));
    }

    private function jobTypeForAction(string $action): string
    {
        return match ($action) {
            'install' => 'musicbot.install',
            'uninstall' => 'musicbot.uninstall',
            'update' => 'musicbot.update',
            'repair' => 'musicbot.repair',
            'status' => 'musicbot.status',
            default => 'musicbot.service.action',
        };
    }

    /** @param array<string, mixed> $extraPayload */
    private function queueJob(string $type, MusicbotInstance $instance, array $extraPayload): AgentJob
    {
        return $this->jobDispatcher->dispatch($instance->getNode(), $type, array_merge([
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'service_name' => $instance->getServiceName(),
            'install_dir' => $instance->getInstallPath(),
            'install_path' => $instance->getInstallPath(),
            'config_file_permissions' => '0600',
            'cpu_limit' => $instance->getCpuLimit(),
            'ram_limit' => $instance->getRamLimit(),
            'disk_limit' => $instance->getDiskLimit(),
        ], $extraPayload));
    }

    private function applyPendingStatus(MusicbotInstance $instance, string $action): void
    {
        match ($action) {
            'install', 'update', 'repair' => $instance->setStatus(MusicbotInstanceStatus::Provisioning),
            'uninstall' => $instance->setStatus(MusicbotInstanceStatus::Stopped),
            'stop' => $instance->setStatus(MusicbotInstanceStatus::Stopped),
            'start', 'restart' => $instance->setStatus(MusicbotInstanceStatus::Running),
            default => null,
        };
    }
}
