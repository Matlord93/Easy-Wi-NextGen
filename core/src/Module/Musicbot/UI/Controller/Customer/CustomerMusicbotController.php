<?php

declare(strict_types=1);

namespace App\Module\Musicbot\UI\Controller\Customer;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Attribute\RequiresModule;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Domain\Enum\ModuleKey;
use App\Module\Musicbot\Application\MusicbotAutoDjService;
use App\Module\Musicbot\Application\MusicbotConfigApplyPayloadBuilder;
use App\Module\Musicbot\Application\MusicbotInstanceService;
use App\Module\Musicbot\Application\MusicbotPlaybackCommandService;
use App\Module\Musicbot\Application\MusicbotPlaylistService;
use App\Module\Musicbot\Application\MusicbotPluginLogService;
use App\Module\Musicbot\Application\MusicbotPluginService;
use App\Module\Musicbot\Application\MusicbotQueueService;
use App\Module\Musicbot\Application\MusicbotQuotaService;
use App\Module\Musicbot\Application\MusicbotPayloadLogSummarizer;
use App\Module\Musicbot\Application\MusicbotRuntimeEventService;
use App\Module\Musicbot\Application\MusicbotRuntimeStatusNormalizer;
use App\Module\Musicbot\Application\MusicbotSecretConfigService;
use App\Module\Musicbot\Application\MusicbotStreamService;
use App\Module\Musicbot\Application\MusicbotTrackService;
use App\Module\Musicbot\Application\MusicbotYoutubeResolverService;
use App\Module\Musicbot\Application\MusicbotYoutubeService;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use App\Module\Musicbot\Domain\Enum\MusicbotPlaylistVisibility;
use App\Module\Musicbot\Domain\Enum\MusicbotRepeatMode;
use App\Module\Musicbot\Domain\Entity\MusicbotQueueItem;
use App\Module\Musicbot\Domain\Exception\MusicbotQuotaExceededException;
use App\Repository\AgentRepository;
use App\Repository\MusicbotConnectionRepository;
use App\Repository\MusicbotInstanceRepository;
use App\Repository\MusicbotPlaylistRepository;
use App\Repository\MusicbotPluginRepository;
use App\Repository\MusicbotQueueItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[Route(path: '/musicbots')]
#[RequiresModule(ModuleKey::Musicbot->value)]
final class CustomerMusicbotController
{
    private const ACTIONS = ['start', 'stop', 'restart', 'status'];

    public function __construct(
        private readonly MusicbotInstanceRepository $instanceRepository,
        private readonly MusicbotConnectionRepository $connectionRepository,
        private readonly MusicbotQueueItemRepository $queueItemRepository,
        private readonly MusicbotPlaylistRepository $playlistRepository,
        private readonly MusicbotPluginRepository $pluginRepository,
        private readonly MusicbotTrackService $trackService,
        private readonly MusicbotPlaylistService $playlistService,
        private readonly MusicbotAutoDjService $autoDjService,
        private readonly MusicbotPluginService $pluginService,
        private readonly MusicbotPluginLogService $pluginLogService,
        private readonly MusicbotQueueService $queueService,
        private readonly MusicbotPlaybackCommandService $playbackCommandService,
        private readonly MusicbotRuntimeEventService $runtimeEventService,
        private readonly MusicbotStreamService $streamService,
        private readonly MusicbotYoutubeResolverService $youtubeResolver,
        private readonly MusicbotYoutubeService $youtubeService,
        private readonly AgentJobDispatcher $jobDispatcher,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly MusicbotInstanceService $instanceService,
        private readonly MusicbotQuotaService $quotaService,
        private readonly MusicbotSecretConfigService $secretConfigService,
        private readonly MusicbotRuntimeStatusNormalizer $runtimeStatusNormalizer,
        private readonly MusicbotConfigApplyPayloadBuilder $configApplyPayloadBuilder,
        private readonly AgentRepository $agentRepository,
    ) {
    }

    #[Route(path: '', name: 'customer_musicbot_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $instances = $this->instanceRepository->findByCustomer($customer);
        $usage = $this->quotaService->usageForCustomer($customer);

        return new Response($this->twig->render('customer/musicbot/index.html.twig', [
            'activeNav' => 'musicbots',
            'instances' => $this->buildIndexRows($instances),
            'usage' => $usage,
            'canCreate' => ($usage['musicbots']['used'] < $usage['musicbots']['max']),
        ]));
    }

    #[Route(path: '/new', name: 'customer_musicbot_new', methods: ['GET'])]
    public function newForm(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $usage = $this->quotaService->usageForCustomer($customer);

        if ($usage['musicbots']['used'] >= $usage['musicbots']['max']) {
            $this->flash($request, 'error', sprintf(
                'Musicbot-Limit erreicht (%d/%d). Bitte Paket upgraden oder einen bestehenden Bot löschen.',
                $usage['musicbots']['used'],
                $usage['musicbots']['max'],
            ));
            return new Response('', Response::HTTP_FOUND, ['Location' => '/musicbots']);
        }

        $limits = $usage['limits'] ?? [];

        return new Response($this->twig->render('customer/musicbot/new.html.twig', [
            'activeNav' => 'musicbots',
            'nodes' => $this->agentRepository->findActive(),
            'limits' => $limits,
        ]));
    }

    #[Route(path: '', name: 'customer_musicbot_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        try {
            $nodeId = (string) $request->request->get('node_id', '');
            $node = $nodeId !== '' ? $this->agentRepository->find($nodeId) : null;
            if ($node === null) {
                $active = $this->agentRepository->findActive();
                $node = $active[0] ?? null;
            }
            if ($node === null) {
                throw new \RuntimeException('Kein aktiver Agent-Node verfügbar. Bitte Anbieter kontaktieren.');
            }

            $instance = $this->instanceService->createInstance(
                customer: $customer,
                node: $node,
                name: (string) $request->request->get('name', ''),
                tsEnabled: $request->request->getBoolean('ts_enabled'),
                tsConfig: [
                    'host' => (string) $request->request->get('ts_host', ''),
                    'port' => (int) $request->request->get('ts_port', 9987),
                    'nickname' => (string) $request->request->get('ts_nickname', 'Musicbot'),
                    'channel_id' => (string) $request->request->get('ts_channel_id', ''),
                ],
                tsSecrets: [
                    'server_password' => (string) $request->request->get('ts_server_password', ''),
                    'channel_password' => (string) $request->request->get('ts_channel_password', ''),
                ],
                autostart: $request->request->getBoolean('autostart'),
                webradioEnabled: $request->request->getBoolean('webradio_enabled'),
            );
        } catch (MusicbotQuotaExceededException $e) {
            $this->flash($request, 'error', $e->getMessage());
            return new Response('', Response::HTTP_FOUND, ['Location' => '/musicbots/new']);
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
            return new Response('', Response::HTTP_FOUND, ['Location' => '/musicbots/new']);
        }

        $this->flash($request, 'success', sprintf('Musicbot „%s" wird jetzt eingerichtet.', $instance->getName()));

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $instance->getId())]);
    }

    #[Route(path: '/{id}', name: 'customer_musicbot_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $queue = $this->queueItemRepository->findQueueForInstanceOrdered($instance);
        $connections = $this->connectionRepository->findBy(['musicbotInstance' => $instance], ['id' => 'ASC']);
        $streamSettings = $this->streamService->getOrCreateSettings($instance);

        $tsConnection = $this->findTsConnection($connections);
        $tsConnectionConfig = $tsConnection?->getConnectionConfig() ?? [];
        $maskedTsSecrets = $this->secretConfigService->mask($tsConnection?->getSecretConfig() ?? []);

        return new Response($this->twig->render('customer/musicbot/show.html.twig', [
            'activeNav' => 'musicbots',
            'instance' => $instance,
            'connections' => $connections,
            'platformStatus' => $this->buildPlatformStatus($instance),
            'runtimeStatus' => $this->buildRuntimeStatus($instance, $queue),
            'queue' => $queue,
            'currentTrack' => $this->resolveCurrentTrack($queue),
            'tracks' => $this->trackService->libraryForCustomer($customer),
            'playlists' => $this->loadPlaylists($customer, $instance),
            'playlistItems' => $this->buildPlaylistItems($customer, $instance),
            'autoDjSettings' => $this->autoDjService->getOrCreateSettings($instance),
            'autoDjStatus' => $this->autoDjService->normalize($this->autoDjService->getOrCreateSettings($instance)),
            'plugins' => $this->pluginService->pluginsForInstance($customer, $instance),
            'pluginLogs' => $this->pluginLogService->forInstance($instance, 20),
            'pluginManifests' => $this->pluginService->availableManifests(),
            'logs' => $this->runtimeEventService->latestForInstance($instance, 30),
            'monitoring' => $this->buildMonitoring($instance, $queue),
            'streamSettings' => $streamSettings,
            'streamStatus' => $this->streamService->getStatus($instance),
            'limits' => $this->quotaService->usageForCustomer($customer)['limits'] ?? [],
            'actions' => self::ACTIONS,
            'youtubeResolverAvailable' => $this->youtubeResolver->isAvailable(),
            'youtubeDiagnostics' => $this->youtubeService->diagnostics($instance),
            'youtubeHistory' => $this->youtubeService->history($instance),
            'instanceConfig' => $instance->getInstanceConfig(),
            'tsConnectionConfig' => $tsConnectionConfig,
            'maskedTsSecrets' => $maskedTsSecrets,
            'tsConnectionEnabled' => $tsConnection?->isEnabled() ?? false,
        ]));
    }

    #[Route(path: '/{id}/settings', name: 'customer_musicbot_settings_update', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function updateSettings(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $section = (string) $request->request->get('_section', 'all');

        try {
            $general = [];
            $tsConfig = [];
            $tsSecrets = [];

            if ($section === 'all' || $section === 'general') {
                $general = [
                    'name' => (string) $request->request->get('name', ''),
                    'autostart' => $request->request->getBoolean('autostart'),
                    'command_prefix' => (string) $request->request->get('command_prefix', '!'),
                    'default_volume' => (int) $request->request->get('default_volume', 50),
                    'auto_dj' => $request->request->getBoolean('auto_dj'),
                    'repeat_default' => (string) $request->request->get('repeat_default', 'off'),
                    'shuffle_default' => $request->request->getBoolean('shuffle_default'),
                ];
            }

            if ($section === 'all' || $section === 'connections') {
                $tsConfig = [
                    'enabled' => $request->request->getBoolean('ts_enabled'),
                    'host' => (string) $request->request->get('ts_host', ''),
                    'port' => (string) $request->request->get('ts_port', '9987'),
                    'nickname' => (string) $request->request->get('ts_nickname', ''),
                    'channel_id' => (string) $request->request->get('ts_channel_id', ''),
                    'channel_name' => (string) $request->request->get('ts_channel_name', ''),
                    'channel_description' => (string) $request->request->get('ts_channel_description', ''),
                    'avatar' => (string) $request->request->get('ts_avatar', ''),
                    'badges' => (string) $request->request->get('ts_badges', ''),
                    'away_status' => (string) $request->request->get('ts_away_status', ''),
                    'default_recording_mode' => (string) $request->request->get('ts_default_recording_mode', ''),
                    'voice_quality' => (string) $request->request->get('ts_voice_quality', ''),
                    'codec' => (string) $request->request->get('ts_codec', ''),
                    'codec_bitrate' => (string) $request->request->get('ts_codec_bitrate', ''),
                    'commands_enabled' => $request->request->getBoolean('ts_commands_enabled'),
                    'chat_scopes' => $request->request->all('ts_chat_scopes'),
                    'command_config' => $this->parseTeamspeakCommandConfig((string) $request->request->get('ts_command_config', '')),
                    'command_prefix' => (string) $request->request->get('command_prefix', ''),
                ];
                $tsSecrets = [
                    'server_password' => (string) $request->request->get('ts_server_password', ''),
                    'channel_password' => (string) $request->request->get('ts_channel_password', ''),
                ];

                $this->streamService->saveSettings($customer, $instance, [
                    'access_mode' => (string) $request->request->get('webradio_access_mode', 'private'),
                    'stream_title' => (string) $request->request->get('webradio_stream_title', ''),
                    'bitrate' => (int) $request->request->get('webradio_bitrate', 128),
                    'format' => (string) $request->request->get('webradio_format', 'mp3'),
                ]);

                if ($request->request->has('webradio_enabled')) {
                    if ($request->request->getBoolean('webradio_enabled')) {
                        $this->streamService->enable($customer, $instance);
                    } else {
                        $this->streamService->disable($customer, $instance);
                    }
                }
            }

            $this->instanceService->updateSettings(
                customer: $customer,
                instance: $instance,
                general: $general,
                tsConfig: $tsConfig,
                tsSecrets: $tsSecrets,
            );

            $applyJob = $this->dispatchConfigApplyJob($instance);
            $statusJob = $this->queueStatusRefresh($customer, $instance);
            $this->auditLogger->log($customer, 'musicbot.config_apply_requested', ['instance_id' => $id, 'job_id' => $applyJob->getId(), 'status_job_id' => $statusJob->getId(), 'section' => $section, 'live_reconnect' => 'queued']);
            $this->entityManager->flush();
            $reconnectJob = $this->queueLiveReconnect($customer, $instance);
            $this->flash($request, 'success', $reconnectJob !== null ? 'Einstellungen wurden gespeichert. Live-Übernahme wurde angefordert; falls nötig führt die Runtime automatisch einen Reconnect aus.' : 'Einstellungen wurden gespeichert. Status wird aktualisiert. Diese Änderung wird nach einem Neustart des Bots übernommen, falls die Runtime kein Live-Apply unterstützt.');

            if ($request->request->get('_action') === 'save_restart') {
                $job = $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.service.action', [
                    'instance_id' => (string) $instance->getId(),
                    'service_name' => $instance->getServiceName(),
                    'install_path' => $instance->getInstallPath(),
                    'action' => 'restart',
                ]);
                $this->auditLogger->log($customer, 'musicbot.settings_save_restart', ['instance_id' => $id, 'job_id' => $job->getId()]);
                $this->entityManager->flush();
                $this->flash($request, 'success', 'Neustart wurde angefordert.');
            }
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        $redirectTab = $section === 'connections' ? 'verbindungen' : 'einstellungen';

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d#tab-%s', $id, $redirectTab)]);
    }

    #[Route(path: '/{id}/connections/test', name: 'customer_musicbot_connection_test', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function testConnection(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);

        try {
            $job = $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.connection.test', [
                'instance_id' => (string) $instance->getId(),
                'service_name' => $instance->getServiceName(),
                'platform' => 'teamspeak',
            ]);
            $this->auditLogger->log($customer, 'musicbot.connection_test', ['instance_id' => $id, 'job_id' => $job->getId()]);
            $this->entityManager->flush();
            $this->flash($request, 'info', 'Verbindungstest gestartet – Ergebnis erscheint in den Logs.');
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d#tab-verbindungen', $id)]);
    }

    #[Route(path: '/{id}/delete', name: 'customer_musicbot_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function deleteInstance(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);

        try {
            $name = $instance->getName();
            $this->instanceService->deleteInstance($customer, $instance);
            $this->flash($request, 'success', sprintf('Musicbot „%s" wurde gelöscht.', $name));
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
            return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
        }

        return new Response('', Response::HTTP_FOUND, ['Location' => '/musicbots']);
    }

    #[Route(path: '/{id}/stream', name: 'customer_musicbot_stream_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateStream(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);

        try {
            $this->streamService->saveSettings($customer, $instance, [
                'access_mode' => (string) $request->request->get('access_mode', 'private'),
                'stream_title' => (string) $request->request->get('stream_title', ''),
                'bitrate' => (int) $request->request->get('bitrate', 128),
                'format' => (string) $request->request->get('format', 'mp3'),
            ]);
            $this->flash($request, 'success', 'Webradio-Einstellungen wurden gespeichert. Die echte Stream-Ausgabe ist noch nicht aktiv.');
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/stream/toggle', name: 'customer_musicbot_stream_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleStream(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);

        try {
            if ($request->request->getBoolean('enabled')) {
                $this->streamService->enable($customer, $instance);
                $this->flash($request, 'success', 'Webradio-Modus wurde aktiviert. Hinweis: Es ist weiterhin nur der Placeholder aktiv, kein echter Broadcast.');
            } else {
                $this->streamService->disable($customer, $instance);
                $this->flash($request, 'success', 'Webradio-Modus wurde deaktiviert.');
            }
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/stream/rotate-token', name: 'customer_musicbot_stream_rotate_token', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rotateStreamToken(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);

        try {
            $result = $this->streamService->rotateToken($customer, $instance);
            $this->flash($request, 'success', 'Stream-Token wurde erfolgreich rotiert. Das neue Token ist auf der Einstellungsseite sichtbar.');
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/action', name: 'customer_musicbot_action', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function action(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $action = strtolower(trim((string) $request->request->get('action', '')));
        if (!in_array($action, self::ACTIONS, true)) {
            return new Response('Unsupported action.', Response::HTTP_BAD_REQUEST);
        }

        $job = $this->jobDispatcher->dispatch($instance->getNode(), $this->jobTypeForAction($action), [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'service_name' => $instance->getServiceName(),
            'install_dir' => $instance->getInstallPath(),
            'install_path' => $instance->getInstallPath(),
            'action' => $action,
        ]);
        $statusJob = $this->queueStatusRefresh($customer, $instance);
        $this->auditLogger->log($customer, sprintf('musicbot.customer_%s', $action), [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'job_id' => $job->getId(),
            'status_job_id' => $statusJob->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $instance->getId())]);
    }

    #[Route(path: '/{id}/queue/{queueItemId}/delete', name: 'customer_musicbot_queue_item_delete', requirements: ['id' => '\d+', 'queueItemId' => '\d+'], methods: ['POST'])]
    public function deleteQueueItem(Request $request, int $id, int $queueItemId): Response
    {
        $customer = $this->requireCustomer($request);
        $this->findInstanceForCustomer($id, $customer);
        $queueItem = $this->queueItemRepository->findOneForCustomer($queueItemId, $customer);
        if (!$queueItem instanceof MusicbotQueueItem) { throw new NotFoundHttpException($this->translator->trans('error_not_found')); }
        $this->queueService->removeQueueItem($customer, $queueItem);
        $this->queueStatusRefresh($customer, $queueItem->getInstance());
        $this->flash($request, 'success', 'Track wurde aus der Queue entfernt. Status wird aktualisiert.');

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/queue/clear', name: 'customer_musicbot_queue_clear', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function clearQueue(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $this->queueService->clearQueue($customer, $instance);
        $this->queueStatusRefresh($customer, $instance);
        $this->flash($request, 'success', 'Queue wurde geleert. Status wird aktualisiert.');

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/queue/sort', name: 'customer_musicbot_queue_sort', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sortQueue(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $rawIds = array_filter(array_map('trim', explode(',', (string) $request->request->get('queue_item_ids', ''))));
        $this->queueService->sortQueue($customer, $instance, array_map('intval', $rawIds));
        $this->queueStatusRefresh($customer, $instance);
        $this->flash($request, 'success', 'Queue wurde neu sortiert. Status wird aktualisiert.');

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/playback', name: 'customer_musicbot_playback', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function playback(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $action = strtolower(trim((string) $request->request->get('action', '')));
        $options = [];

        try {
            if ($action === 'play' && $this->queueItemRepository->findQueueForInstanceOrdered($instance) === []) {
                throw new \InvalidArgumentException('Kein Track oder Webradio ausgewählt.');
            }
            if ($action === 'volume') {
                $rawVolume = trim((string) $request->request->get('volume', ''));
                if ($rawVolume === '' || !is_numeric($rawVolume)) {
                    throw new \InvalidArgumentException('Die Lautstärke muss eine Zahl zwischen 0 und 100 sein.');
                }
                $volume = (int) $rawVolume;
                if ($volume < 0 || $volume > 100) {
                    throw new \InvalidArgumentException('Die Lautstärke muss eine Zahl zwischen 0 und 100 sein.');
                }
                $options['volume'] = $volume;
                $this->playbackCommandService->storeVolume($customer, $instance, $volume);
            }
            if ($action === 'seek') {
                $rawPosition = trim((string) ($request->request->get('position_ms', $request->request->get('seek_ms', ''))));
                if ($rawPosition === '' || !is_numeric($rawPosition)) {
                    throw new \InvalidArgumentException('Die Seek-Position muss numerisch sein.');
                }
                $options['position_ms'] = max(0, (int) $rawPosition);
            }
            if ($action === 'shuffle') {
                $shuffle = $request->request->getBoolean('shuffle');
                $this->playbackCommandService->storeShuffle($customer, $instance, $shuffle);
                $options['shuffle'] = $shuffle;
            }
            if ($action === 'repeat') {
                $repeat = MusicbotRepeatMode::tryFrom((string) $request->request->get('repeat', 'off')) ?? MusicbotRepeatMode::Off;
                $this->playbackCommandService->storeRepeatMode($customer, $instance, $repeat);
                $options['repeat'] = $repeat->value;
            }
            $job = $this->playbackCommandService->dispatchPlaybackAction($customer, $instance, $action, $options);
            $statusJob = $this->queueStatusRefresh($customer, $instance);
            $this->runtimeEventService->record($instance, 'playback.command', 'info', $action, ['options' => $options]);
            $this->auditLogger->log($customer, sprintf('musicbot.playback_%s', $action), ['instance_id' => $id, 'job_id' => $job->getId(), 'status_job_id' => $statusJob->getId(), 'options' => $options]);
            $this->entityManager->flush();
            $this->flash($request, 'success', $action === 'play' ? 'Playback gestartet. Status wird aktualisiert.' : sprintf('Playback-Aktion %s wurde gesendet; der Runtime-Status wird aktualisiert.', $action));
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/tracks/upload', name: 'customer_musicbot_track_upload', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function uploadTrack(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $file = $request->files->get('track_file');
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $this->flash($request, 'error', 'Bitte eine Audiodatei auswählen.');
            return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
        }
        try {
            $track = $this->trackService->uploadTrack($customer, $file, (string) $request->request->get('title', ''), (string) $request->request->get('artist', ''), $instance);
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
            return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
        }
        $this->runtimeEventService->record($instance, 'track.uploaded', 'info', 'Track uploaded.', ['track_id' => $track->getId(), 'sha256' => $track->getSha256()]);
        $this->auditLogger->log($customer, 'musicbot.track_uploaded', ['track_id' => $track->getId(), 'instance_id' => $id]);
        $this->entityManager->flush();
        $this->flash($request, 'success', sprintf('Track „%s" wurde erfolgreich hochgeladen.', $track->getTitle()));

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/tracks/webradio', name: 'customer_musicbot_track_webradio', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function addWebradioTrack(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        try {
            $this->quotaService->assertWebradioAllowed($customer);
            $track = $this->trackService->addWebradioTrack(
                $customer,
                (string) $request->request->get('title', ''),
                (string) $request->request->get('stream_url', ''),
            );
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
            return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
        }
        $this->runtimeEventService->record($instance, 'track.added', 'info', 'Webradio track added.', ['track_id' => $track->getId()]);
        $this->auditLogger->log($customer, 'musicbot.webradio_track_added', ['track_id' => $track->getId(), 'instance_id' => $id]);
        $this->entityManager->flush();
        $action = (string) $request->request->get('_webradio_action', 'add');
        if (in_array($action, ['add_queue', 'play_now'], true)) {
            $this->queueService->addTrackToQueue($customer, $instance, $track, $customer);
            if ($action === 'play_now') {
                $this->queueStatusRefresh($customer, $instance);
                $this->playbackCommandService->dispatchPlaybackAction($customer, $instance, 'play');
            }
        }
        $this->flash($request, 'success', sprintf('Webradio-Track „%s" wurde hinzugefügt.', $track->getTitle()));

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }


    #[Route(path: '/{id}/youtube/search', name: 'customer_musicbot_youtube_search', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function youtubeSearch(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        try {
            $results = $this->youtubeService->search($customer, $instance, (string) $request->request->get('q', ''), (string) $request->request->get('type', 'song'));
            $payload = $instance->getRuntimePayload() ?? [];
            $payload['youtube_search_results'] = $results;
            $instance->setRuntimePayload($payload);
            $this->entityManager->flush();
            $this->flash($request, 'success', sprintf('%d YouTube-Ergebnis(se) gefunden.', count($results)));
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d#youtube', $id)]);
    }

    #[Route(path: '/{id}/youtube/import', name: 'customer_musicbot_youtube_import', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function youtubeImport(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $action = (string) $request->request->get('_youtube_import_action', 'queue');
        try {
            $url = (string) $request->request->get('url', '');
            if ($action === 'play') {
                $result = $this->youtubeService->playUrl($customer, $instance, $url);
            } elseif ($action === 'playlist') {
                $result = $this->youtubeService->importUrl($customer, $instance, $url, ['playlist' => true, 'create_playlist' => true, 'playlist_name' => (string) $request->request->get('playlist_name', 'YouTube Import'), 'shuffle' => $request->request->getBoolean('shuffle')]);
            } else {
                $result = $this->youtubeService->importUrl($customer, $instance, $url, ['playlist' => $request->request->getBoolean('playlist'), 'queue' => true, 'shuffle' => $request->request->getBoolean('shuffle')]);
            }
            $this->queueStatusRefresh($customer, $instance);
            $this->flash($request, 'success', sprintf('YouTube-Import abgeschlossen (%d Track(s)).', $result['tracks_imported'] ?? 1));
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d#youtube', $id)]);
    }

    #[Route(path: '/{id}/youtube/cookies', name: 'customer_musicbot_youtube_cookies', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function saveYoutubeCookies(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        try {
            $this->youtubeService->saveCookies($customer, $instance, (string) $request->request->get('cookies', ''));
            $this->flash($request, 'success', 'YouTube-Cookies wurden sicher für diese Instanz gespeichert.');
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d#youtube', $id)]);
    }

    #[Route(path: '/{id}/tracks/youtube', name: 'customer_musicbot_track_youtube', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function addYoutubeTrack(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $action = (string) $request->request->get('_youtube_action', 'add');
        try {
            $this->quotaService->assertYoutubeAllowed($customer);
            if (!$this->youtubeResolver->isAvailable()) {
                throw new \RuntimeException('YouTube playback requires yt-dlp on the host. Please run Musicbot repair or install yt-dlp.');
            }
            $track = $this->trackService->addYoutubeTrack(
                $customer,
                (string) $request->request->get('youtube_url', ''),
                (string) $request->request->get('title', '') ?: null,
            );
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
            return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
        }
        $this->runtimeEventService->record($instance, 'track.added', 'info', 'YouTube track added.', ['track_id' => $track->getId()]);
        $this->auditLogger->log($customer, 'musicbot.youtube_track_added', ['track_id' => $track->getId(), 'instance_id' => $id]);
        $this->entityManager->flush();
        if (in_array($action, ['add_queue', 'play_now'], true)) {
            $this->queueService->addTrackToQueue($customer, $instance, $track, $customer);
            if ($action === 'play_now') {
                $this->queueStatusRefresh($customer, $instance);
                $this->playbackCommandService->dispatchPlaybackAction($customer, $instance, 'play');
            }
        }
        $this->flash($request, 'success', sprintf('YouTube-Track „%s" wurde hinzugefügt.', $track->getTitle()));

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/tracks/{trackId}/delete', name: 'customer_musicbot_track_delete', requirements: ['id' => '\\d+', 'trackId' => '\\d+'], methods: ['POST'])]
    public function deleteTrack(Request $request, int $id, int $trackId): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $track = $this->trackService->findTrackForCustomer($trackId, $customer);
        if (!$track instanceof \App\Module\Musicbot\Domain\Entity\MusicbotTrack) {
            throw new NotFoundHttpException($this->translator->trans('error_not_found'));
        }
        $this->trackService->deleteTrack($customer, $track);
        $this->runtimeEventService->record($instance, 'track.deleted', 'info', 'Track deleted.', ['track_id' => $trackId]);
        $this->auditLogger->log($customer, 'musicbot.track_deleted', ['track_id' => $trackId, 'instance_id' => $id]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/tracks/{trackId}/queue', name: 'customer_musicbot_track_queue', requirements: ['id' => '\\d+', 'trackId' => '\\d+'], methods: ['POST'])]
    public function queueTrack(Request $request, int $id, int $trackId): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $track = $this->trackService->findTrackForCustomer($trackId, $customer);
        if (!$track instanceof \App\Module\Musicbot\Domain\Entity\MusicbotTrack) {
            throw new NotFoundHttpException($this->translator->trans('error_not_found'));
        }
        $queueItem = $this->queueService->addTrackToQueue($customer, $instance, $track, $customer);
        $this->runtimeEventService->record($instance, 'queue.updated', 'info', 'Queue updated.');
        $this->auditLogger->log($customer, 'musicbot.track_queued', ['track_id' => $trackId, 'queue_item_id' => $queueItem->getId(), 'instance_id' => $id]);
        $this->entityManager->flush();
        $this->flash($request, 'success', sprintf('„%s" wurde zur Queue hinzugefügt.', $track->getTitle()));

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/tracks/{trackId}/play', name: 'customer_musicbot_track_play_now', requirements: ['id' => '\\d+', 'trackId' => '\\d+'], methods: ['POST'])]
    public function playTrackNow(Request $request, int $id, int $trackId): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $track = $this->trackService->findTrackForCustomer($trackId, $customer);
        if (!$track instanceof \App\Module\Musicbot\Domain\Entity\MusicbotTrack) {
            throw new NotFoundHttpException($this->translator->trans('error_not_found'));
        }
        try {
            $queueItem = $this->queueService->prependTrackToQueue($customer, $instance, $track, $customer);
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
            return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
        }
        $job = $this->playbackCommandService->dispatchPlaybackAction($customer, $instance, 'play');
        $this->runtimeEventService->record($instance, 'queue.updated', 'info', 'Track prepended for play-now.', ['track_id' => $trackId, 'queue_item_id' => $queueItem->getId()]);
        $statusJob = $this->queueStatusRefresh($customer, $instance);
        $this->auditLogger->log($customer, 'musicbot.track_play_now', ['track_id' => $trackId, 'queue_item_id' => $queueItem->getId(), 'instance_id' => $id, 'job_id' => $job->getId(), 'status_job_id' => $statusJob->getId()]);
        $this->entityManager->flush();
        $this->flash($request, 'success', sprintf('„%s" wird jetzt abgespielt.', $track->getTitle()));

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/playlists', name: 'customer_musicbot_playlist_create', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function createPlaylist(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $visibility = MusicbotPlaylistVisibility::tryFrom((string) $request->request->get('visibility', 'private')) ?? MusicbotPlaylistVisibility::Private;
        $playlist = $this->playlistService->createPlaylist($customer, (string) $request->request->get('name', ''), $instance, $visibility, (string) $request->request->get('description', ''));
        $this->runtimeEventService->record($instance, 'playlist.created', 'info', 'Playlist created.', ['playlist_id' => $playlist->getId()]);
        $this->auditLogger->log($customer, 'musicbot.playlist_created', ['playlist_id' => $playlist->getId(), 'instance_id' => $id]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/playlists/{playlistId}/update', name: 'customer_musicbot_playlist_update', requirements: ['id' => '\\d+', 'playlistId' => '\\d+'], methods: ['POST'])]
    public function updatePlaylist(Request $request, int $id, int $playlistId): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $playlist = $this->playlistService->findPlaylistForCustomer($playlistId, $customer);
        if (!$playlist instanceof \App\Module\Musicbot\Domain\Entity\MusicbotPlaylist) { throw new NotFoundHttpException($this->translator->trans('error_not_found')); }
        $visibility = MusicbotPlaylistVisibility::tryFrom((string) $request->request->get('visibility', 'private')) ?? MusicbotPlaylistVisibility::Private;
        $this->playlistService->updatePlaylist($customer, $playlist, (string) $request->request->get('name', ''), $visibility, (string) $request->request->get('description', ''));
        $this->runtimeEventService->record($instance, 'playlist.updated', 'info', 'Playlist updated.', ['playlist_id' => $playlistId]);
        $this->auditLogger->log($customer, 'musicbot.playlist_updated', ['playlist_id' => $playlistId, 'instance_id' => $id]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/playlists/{playlistId}/delete', name: 'customer_musicbot_playlist_delete', requirements: ['id' => '\\d+', 'playlistId' => '\\d+'], methods: ['POST'])]
    public function deletePlaylist(Request $request, int $id, int $playlistId): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $playlist = $this->playlistService->findPlaylistForCustomer($playlistId, $customer);
        if (!$playlist instanceof \App\Module\Musicbot\Domain\Entity\MusicbotPlaylist) { throw new NotFoundHttpException($this->translator->trans('error_not_found')); }
        $this->playlistService->deletePlaylist($customer, $playlist);
        $this->runtimeEventService->record($instance, 'playlist.deleted', 'info', 'Playlist deleted.', ['playlist_id' => $playlistId]);
        $this->auditLogger->log($customer, 'musicbot.playlist_deleted', ['playlist_id' => $playlistId, 'instance_id' => $id]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/playlists/{playlistId}/tracks', name: 'customer_musicbot_playlist_track_add', requirements: ['id' => '\\d+', 'playlistId' => '\\d+'], methods: ['POST'])]
    public function addTrackToPlaylist(Request $request, int $id, int $playlistId): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $playlist = $this->playlistService->findPlaylistForCustomer($playlistId, $customer);
        $track = $this->trackService->findTrackForCustomer((int) $request->request->get('track_id'), $customer);
        if (!$playlist instanceof \App\Module\Musicbot\Domain\Entity\MusicbotPlaylist || !$track instanceof \App\Module\Musicbot\Domain\Entity\MusicbotTrack) { throw new NotFoundHttpException($this->translator->trans('error_not_found')); }
        $item = $this->playlistService->addTrack($customer, $playlist, $track);
        $this->runtimeEventService->record($instance, 'playlist.updated', 'info', 'Playlist track added.', ['playlist_id' => $playlistId, 'track_id' => $track->getId(), 'playlist_item_id' => $item->getId()]);
        $this->auditLogger->log($customer, 'musicbot.playlist_track_added', ['playlist_id' => $playlistId, 'playlist_item_id' => $item->getId(), 'track_id' => $track->getId(), 'instance_id' => $id]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/playlists/items/{itemId}/delete', name: 'customer_musicbot_playlist_track_remove', requirements: ['id' => '\\d+', 'itemId' => '\\d+'], methods: ['POST'])]
    public function removeTrackFromPlaylist(Request $request, int $id, int $itemId): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $item = $this->playlistService->findPlaylistItemForCustomer($itemId, $customer);
        if (!$item instanceof \App\Module\Musicbot\Domain\Entity\MusicbotPlaylistItem) { throw new NotFoundHttpException($this->translator->trans('error_not_found')); }
        $playlistId = $item->getPlaylist()->getId();
        $this->playlistService->removeItem($customer, $item);
        $this->runtimeEventService->record($instance, 'playlist.updated', 'info', 'Playlist track removed.', ['playlist_id' => $playlistId, 'playlist_item_id' => $itemId]);
        $this->auditLogger->log($customer, 'musicbot.playlist_track_removed', ['playlist_id' => $playlistId, 'playlist_item_id' => $itemId, 'instance_id' => $id]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/playlists/{playlistId}/queue', name: 'customer_musicbot_playlist_queue', requirements: ['id' => '\\d+', 'playlistId' => '\\d+'], methods: ['POST'])]
    public function queuePlaylist(Request $request, int $id, int $playlistId): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $playlist = $this->playlistService->findPlaylistForCustomer($playlistId, $customer);
        if (!$playlist instanceof \App\Module\Musicbot\Domain\Entity\MusicbotPlaylist) { throw new NotFoundHttpException($this->translator->trans('error_not_found')); }
        $mode = (string) $request->request->get('mode', 'add');
        $queueItems = $this->playlistService->loadPlaylistToQueueMode($customer, $playlist, $instance, $mode);
        $job = null;
        if (in_array($mode, ['play_now', 'clear_play', 'shuffle_play'], true) && $queueItems !== []) {
            $job = $this->playbackCommandService->dispatchPlaybackAction($customer, $instance, 'play');
        }
        $statusJob = $this->queueStatusRefresh($customer, $instance);
        $this->runtimeEventService->record($instance, 'queue.updated', 'info', 'Playlist loaded into queue.', ['playlist_id' => $playlistId, 'queued_tracks' => count($queueItems), 'mode' => $mode]);
        $this->auditLogger->log($customer, 'musicbot.playlist_queued', ['playlist_id' => $playlistId, 'queued_tracks' => count($queueItems), 'instance_id' => $id, 'mode' => $mode, 'job_id' => $job?->getId(), 'status_job_id' => $statusJob->getId()]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/playlists/{playlistId}/reorder', name: 'customer_musicbot_playlist_reorder', requirements: ['id' => '\\d+', 'playlistId' => '\\d+'], methods: ['POST'])]
    public function reorderPlaylist(Request $request, int $id, int $playlistId): Response
    {
        $customer = $this->requireCustomer($request);
        $this->findInstanceForCustomer($id, $customer);
        $playlist = $this->playlistService->findPlaylistForCustomer($playlistId, $customer);
        if (!$playlist instanceof \App\Module\Musicbot\Domain\Entity\MusicbotPlaylist) { throw new NotFoundHttpException($this->translator->trans('error_not_found')); }
        $rawIds = array_filter(array_map('trim', explode(',', (string) $request->request->get('playlist_item_ids', ''))));
        $this->playlistService->reorderItems($customer, $playlist, array_map('intval', $rawIds));
        $this->flash($request, 'success', 'Playlist-Reihenfolge wurde gespeichert.');

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }


    #[Route(path: '/{id}/autodj', name: 'customer_musicbot_autodj_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function saveAutoDj(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $playlistIds = array_filter(array_map('intval', (array) $request->request->all('playlist_ids')));
        $data = [
            'enabled' => $request->request->getBoolean('enabled'),
            'fallback_playlist_id' => $request->request->get('fallback_playlist_id') !== '' ? (int) $request->request->get('fallback_playlist_id') : null,
            'playlist_ids' => $playlistIds,
            'mode' => (string) $request->request->get('mode', 'random'),
            'shuffle' => $request->request->getBoolean('shuffle'),
            'repeat' => $request->request->getBoolean('repeat'),
            'avoid_repeats' => $request->request->getBoolean('avoid_repeats'),
            'avoid_same_artist' => $request->request->getBoolean('avoid_same_artist'),
            'min_queue_size' => (int) $request->request->get('min_queue_size', 2),
            'idle_seconds' => (int) $request->request->get('idle_seconds', 60),
            'volume_override' => $request->request->get('volume_override') !== '' ? (int) $request->request->get('volume_override') : null,
            'time_window_start' => (string) $request->request->get('time_window_start', ''),
            'time_window_end' => (string) $request->request->get('time_window_end', ''),
            'webradio_fallback_url' => (string) $request->request->get('webradio_fallback_url', ''),
            'allow_youtube' => $request->request->getBoolean('allow_youtube'),
            'allow_uploads' => $request->request->getBoolean('allow_uploads'),
            'repeat_protection_window' => (int) $request->request->get('repeat_protection_window', 5),
            'genre_filter' => (string) $request->request->get('genre_filter', ''),
        ];
        try {
            $settings = $this->autoDjService->saveSettings($customer, $instance, $data);
            $this->flash($request, 'success', $settings->isEnabled() ? 'AutoDJ wurde gespeichert und aktiviert.' : 'AutoDJ wurde gespeichert und deaktiviert.');
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/autodj/trigger', name: 'customer_musicbot_autodj_trigger', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function triggerAutoDj(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        try {
            $added = $this->autoDjService->trigger($customer, $instance);
            $this->queueStatusRefresh($customer, $instance);
            $this->flash($request, 'success', sprintf('AutoDJ hat %d Track(s) in die Queue geladen. Status wird aktualisiert.', $added));
        } catch (\Throwable $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/autodj/disable', name: 'customer_musicbot_autodj_disable', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function disableAutoDj(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $this->autoDjService->disable($customer, $instance);
        $this->flash($request, 'success', 'AutoDJ wurde deaktiviert.');

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/plugins', name: 'customer_musicbot_plugin_assign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assignPlugin(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $identifier = (string) $request->request->get('identifier', '');
        try {
            $plugin = $this->pluginService->assignPlugin($customer, $instance, $identifier);
        } catch (\InvalidArgumentException $e) {
            $this->flash($request, 'error', $e->getMessage());
            return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
        }
        $this->runtimeEventService->record($instance, 'plugin.changed', 'info', 'Plugin assigned.', ['plugin_id' => $plugin->getId(), 'identifier' => $plugin->getIdentifier()]);
        $this->auditLogger->log($customer, 'musicbot.plugin_assigned', ['instance_id' => $id, 'plugin_id' => $plugin->getId(), 'identifier' => $plugin->getIdentifier()]);
        $this->entityManager->flush();
        $this->flash($request, 'success', 'Plugin wurde zugewiesen.');

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/plugins/{pluginId}/toggle', name: 'customer_musicbot_plugin_toggle', requirements: ['id' => '\d+', 'pluginId' => '\d+'], methods: ['POST'])]
    public function togglePlugin(Request $request, int $id, int $pluginId): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $plugin = $this->pluginService->findPluginForCustomer($pluginId, $customer);
        if (!$plugin instanceof \App\Module\Musicbot\Domain\Entity\MusicbotPlugin || $plugin->getInstance() !== $instance) {
            throw new NotFoundHttpException($this->translator->trans('error_not_found'));
        }
        $enabled = $request->request->getBoolean('enabled');
        $this->pluginService->setEnabled($customer, $plugin, $enabled);
        $this->runtimeEventService->record($instance, 'plugin.changed', 'info', $enabled ? 'Plugin enabled.' : 'Plugin disabled.', ['plugin_id' => $plugin->getId(), 'identifier' => $plugin->getIdentifier(), 'enabled' => $enabled]);
        $this->auditLogger->log($customer, 'musicbot.plugin_toggled', ['instance_id' => $id, 'plugin_id' => $plugin->getId(), 'enabled' => $enabled]);
        $this->entityManager->flush();
        $this->flash($request, 'success', 'Plugin-Status wurde gespeichert.');

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/plugins/{pluginId}/config', name: 'customer_musicbot_plugin_config', requirements: ['id' => '\d+', 'pluginId' => '\d+'], methods: ['POST'])]
    public function savePluginConfig(Request $request, int $id, int $pluginId): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $plugin = $this->pluginService->findPluginForCustomer($pluginId, $customer);
        if (!$plugin instanceof \App\Module\Musicbot\Domain\Entity\MusicbotPlugin || $plugin->getInstance() !== $instance) {
            throw new NotFoundHttpException($this->translator->trans('error_not_found'));
        }
        $rawConfig = trim((string) $request->request->get('config_json', '{}'));
        $config = json_decode($rawConfig === '' ? '{}' : $rawConfig, true);
        if (!is_array($config)) {
            $this->flash($request, 'error', 'Plugin-Konfiguration muss valides JSON sein.');
            return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
        }
        try {
            $this->pluginService->saveConfig($customer, $plugin, $config);
        } catch (\InvalidArgumentException $e) {
            $this->flash($request, 'error', $e->getMessage());
            return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
        }
        $this->runtimeEventService->record($instance, 'plugin.changed', 'info', 'Plugin config updated.', ['plugin_id' => $plugin->getId(), 'identifier' => $plugin->getIdentifier()]);
        $this->auditLogger->log($customer, 'musicbot.plugin_config_saved', ['instance_id' => $id, 'plugin_id' => $plugin->getId()]);
        $this->entityManager->flush();
        $this->flash($request, 'success', 'Plugin-Konfiguration wurde gespeichert.');

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    /** @param MusicbotConnection[] $connections */
    private function findTsConnection(array $connections): ?MusicbotConnection
    {
        foreach ($connections as $connection) {
            if ($connection->getPlatform() === MusicbotPlatform::Teamspeak) {
                return $connection;
            }
        }

        return null;
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('session', $this->translator->trans('error_unauthorized'));
        }

        return $actor;
    }

    private function findInstanceForCustomer(int $id, User $customer): MusicbotInstance
    {
        $instance = $this->instanceRepository->findOneForCustomer($id, $customer);
        if (!$instance instanceof MusicbotInstance) {
            throw new NotFoundHttpException($this->translator->trans('error_not_found'));
        }

        return $instance;
    }

    /**
     * @param MusicbotInstance[] $instances
     * @return array<int, array<string, mixed>>
     */
    private function buildIndexRows(array $instances): array
    {
        return array_map(function (MusicbotInstance $instance): array {
            $connections = $this->connectionRepository->findBy(['musicbotInstance' => $instance], ['id' => 'ASC']);
            $queue = $this->queueItemRepository->findQueueForInstanceOrdered($instance);

            return [
                'instance' => $instance,
                'teamspeakActive' => $this->isPlatformEnabled($connections, 'teamspeak'),
                'discordActive' => $this->isPlatformEnabled($connections, 'discord'),
                'nowPlaying' => $this->runtimeStatusNormalizer->buildNowPlaying(
                    $this->runtimeStatusNormalizer->normalizePayload($instance->getRuntimePayload() ?? []),
                    $this->queueItemForNowPlaying($queue),
                ),
                'queueLength' => count($queue),
            ];
        }, $instances);
    }

    /** @param MusicbotConnection[] $connections */
    private function isPlatformEnabled(array $connections, string $platform): bool
    {
        foreach ($connections as $connection) {
            if ($connection->getPlatform()->value === $platform && $connection->isEnabled()) {
                return true;
            }
        }

        return false;
    }

    /** @param MusicbotQueueItem[] $queue */
    private function resolveCurrentTrack(array $queue): ?\App\Module\Musicbot\Domain\Entity\MusicbotTrack
    {
        foreach ($queue as $queueItem) {
            if (in_array($queueItem->getStatus(), ['playing', 'current'], true)) {
                return $queueItem->getTrack();
            }
        }

        if ($queue === []) {
            return null;
        }

        return $queue[0]->getTrack();
    }

    /** @return array<int, \App\Module\Musicbot\Domain\Entity\MusicbotPlaylist> */
    private function loadPlaylists(User $customer, MusicbotInstance $instance): array
    {
        return array_values(array_filter(
            $this->playlistRepository->findByCustomer($customer),
            static fn ($playlist): bool => $playlist->getInstance() === null || $playlist->getInstance() === $instance,
        ));
    }

    /** @return array<int, array<int, \App\Module\Musicbot\Domain\Entity\MusicbotPlaylistItem>> */
    private function buildPlaylistItems(User $customer, MusicbotInstance $instance): array
    {
        $items = [];
        foreach ($this->loadPlaylists($customer, $instance) as $playlist) {
            $id = $playlist->getId();
            if ($id !== null) {
                $items[$id] = $this->playlistService->itemsForPlaylist($customer, $playlist);
            }
        }

        return $items;
    }

    /** @param MusicbotQueueItem[] $queue @return array<string, mixed>|null */
    private function queueItemForNowPlaying(array $queue): ?array
    {
        $item = null;
        foreach ($queue as $candidate) {
            if (in_array($candidate->getStatus(), ['playing', 'current'], true)) {
                $item = $candidate;
                break;
            }
        }
        $item ??= $queue[0] ?? null;
        if (!$item instanceof MusicbotQueueItem) {
            return null;
        }

        $track = $item->getTrack();
        $metadata = $track->getMetadata();

        return [
            'title' => $track->getTitle(),
            'artist' => $track->getArtist(),
            'source_type' => $track->getSourceType()->value,
            'url' => $metadata['stream_url'] ?? $metadata['youtube_url'] ?? $metadata['url'] ?? null,
            'thumbnail' => $metadata['thumbnail'] ?? $metadata['thumbnail_url'] ?? $metadata['cover_url'] ?? null,
            'queue_item_id' => $item->getId(),
            'track_id' => $track->getId(),
        ];
    }

    /** @param MusicbotQueueItem[] $queue */
    private function buildRuntimeStatus(MusicbotInstance $instance, array $queue): array
    {
        $payload = $this->sanitizeForTemplate($this->runtimeStatusNormalizer->normalizePayload($instance->getRuntimePayload() ?? []));
        if ((string) ($_ENV['MUSICBOT_DEBUG_STATUS_FLOW'] ?? $_SERVER['MUSICBOT_DEBUG_STATUS_FLOW'] ?? getenv('MUSICBOT_DEBUG_STATUS_FLOW') ?: '') === '1') {
            error_log('[musicbot-status-flow] '.json_encode(['stage' => 'customer.controller.payload.normalized', 'instance_id' => $instance->getId()] + MusicbotPayloadLogSummarizer::summarizeRuntimePayload($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        $playback = is_array($payload['playback'] ?? null) ? $payload['playback'] : [];
        $instanceConfig = $instance->getInstanceConfig();
        $volume = (int) ($playback['volume'] ?? $instanceConfig['live_volume'] ?? 50);
        if (isset($instanceConfig['live_volume']) && is_numeric($instanceConfig['live_volume'])) {
            $volume = max(0, min(100, (int) $instanceConfig['live_volume']));
        }
        $ps = is_array($payload['playback_status'] ?? null) ? $payload['playback_status'] : [];
        $pipeline = is_array($payload['audio_pipeline'] ?? null) ? $payload['audio_pipeline'] : [];
        $normal = is_array($payload['playback_status'] ?? null) ? $payload['playback_status'] : $this->runtimeStatusNormalizer->buildPlaybackStatus($payload);
        $audioReady = (bool) $normal['audio_backend_ready'];
        $nowPlaying = $this->runtimeStatusNormalizer->buildNowPlaying($payload, $this->queueItemForNowPlaying($queue));

        return [
            'state' => $instance->getStatus()->value,
            'lastError' => $this->runtimeStatusNormalizer->resolveActiveLastError($payload, $normal),
            'runtimePayload' => $payload,
            'nowPlaying' => $nowPlaying,
            'queueLength' => count($queue),
            'repeatMode' => (string) ($ps['repeat_mode'] ?? $playback['repeat_mode'] ?? $playback['repeat'] ?? 'off'),
            'shuffle' => (bool) ($ps['shuffle'] ?? $playback['shuffle'] ?? false),
            'volume' => $volume,
            'playbackState' => (string) ($ps['playback_state'] ?? $instance->getStatus()->value),
            'playbackPositionMs' => (int) ($ps['playback_position_ms'] ?? 0),
            'durationMs' => (int) ($ps['duration_ms'] ?? 0),
            'decoderBackend' => (string) ($ps['decoder_backend'] ?? ''),
            'decoderStatus' => (string) ($ps['decoder_status'] ?? ''),
            'outputBackend' => (string) ($normal['output_backend'] ?? ''),
            'outputStatus' => (string) ($normal['output_status'] ?? ''),
            'framesProcessed' => (int) ($ps['frames_processed'] ?? 0),
            'lastStateChangeAt' => (string) ($ps['last_state_change_at'] ?? ''),
            'audioBackendReady' => $audioReady,
            'audioBackendStatus' => (string) $normal['audio_backend_status'],
            'audioBackendMessage' => (string) $normal['audio_backend_message'],
            'teamspeakConnected' => (bool) $normal['teamspeak_connected'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function buildPlatformStatus(MusicbotInstance $instance): array
    {
        $status = [
            'teamspeak' => ['enabled' => false, 'status' => 'missing', 'lastError' => null, 'profile' => 'ts3', 'backend' => 'ts3_client_compatible', 'capability_status' => 'client_backend_required'],
            'discord' => ['enabled' => false, 'status' => 'missing', 'lastError' => null, 'capability_status' => 'voice_backend_required'],
        ];
        foreach ($this->connectionRepository->findBy(['musicbotInstance' => $instance], ['id' => 'ASC']) as $connection) {
            $platform = $connection->getPlatform()->value;
            $config = $connection->getConnectionConfig();
            $status[$platform] = [
                'enabled' => $connection->isEnabled(),
                'status' => $connection->getStatus()->value,
                'lastError' => $this->sanitizeTextForTemplate($connection->getLastError()),
            ] + ($platform === 'teamspeak' ? [
                'profile' => $connection->getTeamspeakProfile()->value,
                'backend' => $connection->getTeamspeakBackend(),
                'capability_status' => (string) ($config['capability_status'] ?? 'client_backend_required'),
            ] : [
                'capability_status' => (string) ($config['capability_status'] ?? 'voice_backend_required'),
            ]);
        }

        return $status;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeForTemplate(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 20) {
            return '[truncated]';
        }
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
            $sanitized[$key] = $this->sanitizeForTemplate($item, $depth + 1);
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
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add($type, $message);
        }
    }

    /** @param MusicbotQueueItem[] $queue @return array<string, mixed> */
    private function buildMonitoring(MusicbotInstance $instance, array $queue): array
    {
        $payload = $this->sanitizeForTemplate($this->runtimeStatusNormalizer->normalizePayload($instance->getRuntimePayload() ?? []));
        if ((string) ($_ENV['MUSICBOT_DEBUG_STATUS_FLOW'] ?? $_SERVER['MUSICBOT_DEBUG_STATUS_FLOW'] ?? getenv('MUSICBOT_DEBUG_STATUS_FLOW') ?: '') === '1') {
            error_log('[musicbot-status-flow] '.json_encode(['stage' => 'customer.controller.payload.normalized', 'instance_id' => $instance->getId()] + MusicbotPayloadLogSummarizer::summarizeRuntimePayload($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        $playback = is_array($payload['playback'] ?? null) ? $payload['playback'] : [];
        return [
            'cpu' => $payload['cpu'] ?? $payload['metrics']['cpu'] ?? null,
            'ram' => $payload['ram'] ?? $payload['metrics']['ram'] ?? null,
            'queueLength' => count($queue),
            'currentTrack' => $this->resolveCurrentTrack($queue),
            'lastPlaybackAction' => $playback['last_command'] ?? $payload['last_playback_action'] ?? null,
            'lastRuntimeHeartbeat' => $payload['heartbeat_at'] ?? $payload['updated_at'] ?? null,
        ];
    }


    /** @return array<string, mixed> */
    private function parseTeamspeakCommandConfig(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }


    private function queueLiveReconnect(User $customer, MusicbotInstance $instance): ?\App\Module\AgentOrchestrator\Domain\Entity\AgentJob
    {
        $job = $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.playback.action', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'service_name' => $instance->getServiceName(),
            'install_path' => $instance->getInstallPath(),
            'action' => 'reload_config',
            'command' => 'reload_config',
            'reconnect_if_required' => true,
            'fallback_hint' => 'Diese Änderung wird nach einem Neustart des Bots übernommen, falls Live-Apply oder Reconnect von der Runtime nicht unterstützt wird.',
        ]);
        $this->auditLogger->log($customer, 'musicbot.live_reconnect_requested', ['instance_id' => $instance->getId(), 'job_id' => $job->getId()]);

        return $job;
    }

    private function dispatchConfigApplyJob(MusicbotInstance $instance): \App\Module\AgentOrchestrator\Domain\Entity\AgentJob
    {
        return $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.config.apply', $this->configApplyPayloadBuilder->build($instance));
    }

    private function queueStatusRefresh(User $customer, MusicbotInstance $instance): \App\Module\AgentOrchestrator\Domain\Entity\AgentJob
    {
        return $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.status', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'service_name' => $instance->getServiceName(),
            'install_dir' => $instance->getInstallPath(),
            'install_path' => $instance->getInstallPath(),
            'action' => 'status',
        ]);
    }

    private function jobTypeForAction(string $action): string
    {
        return $action === 'status' ? 'musicbot.status' : 'musicbot.service.action';
    }
}
