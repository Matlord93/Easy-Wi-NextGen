<?php

declare(strict_types=1);

namespace App\Module\Musicbot\UI\Controller\Customer;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Attribute\RequiresModule;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Domain\Enum\ModuleKey;
use App\Module\Musicbot\Application\MusicbotInstanceService;
use App\Module\Musicbot\Application\MusicbotPlaybackCommandService;
use App\Module\Musicbot\Application\MusicbotPlaylistService;
use App\Module\Musicbot\Application\MusicbotPluginService;
use App\Module\Musicbot\Application\MusicbotQueueService;
use App\Module\Musicbot\Application\MusicbotQuotaService;
use App\Module\Musicbot\Application\MusicbotRuntimeEventService;
use App\Module\Musicbot\Application\MusicbotSecretConfigService;
use App\Module\Musicbot\Application\MusicbotStreamService;
use App\Module\Musicbot\Application\MusicbotTrackService;
use App\Module\Musicbot\Application\MusicbotYoutubeResolverService;
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
        private readonly MusicbotPluginService $pluginService,
        private readonly MusicbotQueueService $queueService,
        private readonly MusicbotPlaybackCommandService $playbackCommandService,
        private readonly MusicbotRuntimeEventService $runtimeEventService,
        private readonly MusicbotStreamService $streamService,
        private readonly MusicbotYoutubeResolverService $youtubeResolver,
        private readonly AgentJobDispatcher $jobDispatcher,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly MusicbotInstanceService $instanceService,
        private readonly MusicbotQuotaService $quotaService,
        private readonly MusicbotSecretConfigService $secretConfigService,
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
            'plugins' => $this->pluginService->pluginsForInstance($customer, $instance),
            'pluginManifests' => $this->pluginService->availableManifests(),
            'logs' => $this->runtimeEventService->latestForInstance($instance, 30),
            'monitoring' => $this->buildMonitoring($instance, $queue),
            'streamSettings' => $streamSettings,
            'streamStatus' => $this->streamService->getStatus($instance),
            'actions' => self::ACTIONS,
            'youtubeResolverAvailable' => $this->youtubeResolver->isAvailable(),
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
                    'port' => (int) $request->request->get('ts_port', 9987),
                    'nickname' => (string) $request->request->get('ts_nickname', ''),
                    'channel_id' => (string) $request->request->get('ts_channel_id', ''),
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

            $this->flash($request, 'success', 'Einstellungen wurden gespeichert.');

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
            $this->flash($request, 'success', sprintf('Neues Stream-Token: %s (wird nur einmal angezeigt).', $result['token']));
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
        $this->auditLogger->log($customer, sprintf('musicbot.customer_%s', $action), [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'job_id' => $job->getId(),
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
        $this->flash($request, 'success', 'Track wurde aus der Queue entfernt.');

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/queue/clear', name: 'customer_musicbot_queue_clear', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function clearQueue(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $this->queueService->clearQueue($customer, $instance);
        $this->flash($request, 'success', 'Queue wurde geleert.');

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/queue/sort', name: 'customer_musicbot_queue_sort', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sortQueue(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $rawIds = array_filter(array_map('trim', explode(',', (string) $request->request->get('queue_item_ids', ''))));
        $this->queueService->sortQueue($customer, $instance, array_map('intval', $rawIds));
        $this->flash($request, 'success', 'Queue wurde neu sortiert.');

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/playback', name: 'customer_musicbot_playback', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function playback(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $action = strtolower(trim((string) $request->request->get('action', '')));
        $options = [];
        if ($action === 'volume') { $options['volume'] = max(0, min(100, (int) $request->request->get('volume', 50))); }
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
        $this->runtimeEventService->record($instance, 'playback.command', 'info', $action, ['options' => $options]);
        $this->auditLogger->log($customer, sprintf('musicbot.playback_%s', $action), ['instance_id' => $id, 'job_id' => $job->getId(), 'options' => $options]);
        $this->entityManager->flush();
        $this->flash($request, 'success', sprintf('Playback-Aktion %s wurde gesendet.', $action));

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
            $track = $this->trackService->uploadTrack($customer, $file, (string) $request->request->get('title', ''), (string) $request->request->get('artist', ''));
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
        $this->flash($request, 'success', sprintf('Webradio-Track „%s" wurde hinzugefügt.', $track->getTitle()));

        return new Response('', Response::HTTP_FOUND, ['Location' => sprintf('/musicbots/%d', $id)]);
    }

    #[Route(path: '/{id}/tracks/youtube', name: 'customer_musicbot_track_youtube', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function addYoutubeTrack(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        try {
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
        $this->auditLogger->log($customer, 'musicbot.track_play_now', ['track_id' => $trackId, 'queue_item_id' => $queueItem->getId(), 'instance_id' => $id, 'job_id' => $job->getId()]);
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
        $playlist = $this->playlistService->createPlaylist($customer, (string) $request->request->get('name', ''), $instance, $visibility);
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
        $this->playlistService->updatePlaylist($customer, $playlist, (string) $request->request->get('name', ''), $visibility);
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
        $queueItems = $this->playlistService->loadPlaylistToQueue($customer, $playlist, $instance);
        $this->runtimeEventService->record($instance, 'queue.updated', 'info', 'Playlist loaded into queue.', ['playlist_id' => $playlistId, 'queued_tracks' => count($queueItems)]);
        $this->auditLogger->log($customer, 'musicbot.playlist_queued', ['playlist_id' => $playlistId, 'queued_tracks' => count($queueItems), 'instance_id' => $id]);
        $this->entityManager->flush();

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
                'currentTrack' => $this->resolveCurrentTrack($queue),
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

    /** @return array<int, \App\Module\Musicbot\Domain\Entity\MusicbotPlugin> */
    private function loadPlugins(User $customer, MusicbotInstance $instance): array
    {
        return array_values(array_filter(
            $this->pluginRepository->findByCustomer($customer),
            static fn ($plugin): bool => $plugin->getInstance() === null || $plugin->getInstance() === $instance,
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


    /** @param MusicbotQueueItem[] $queue */
    private function buildRuntimeStatus(MusicbotInstance $instance, array $queue): array
    {
        $payload = $this->sanitizeForTemplate($instance->getRuntimePayload() ?? []);
        $playback = is_array($payload['playback'] ?? null) ? $payload['playback'] : [];
        $ps = is_array($payload['playback_status'] ?? null) ? $payload['playback_status'] : [];

        return [
            'state' => $instance->getStatus()->value,
            'lastError' => $payload['last_error'] ?? $payload['error'] ?? (is_string($ps['last_error'] ?? null) && $ps['last_error'] !== '' ? $ps['last_error'] : null),
            'runtimePayload' => $payload,
            'currentTrack' => $this->resolveCurrentTrack($queue),
            'queueLength' => count($queue),
            'repeatMode' => (string) ($ps['repeat_mode'] ?? $playback['repeat_mode'] ?? $playback['repeat'] ?? 'off'),
            'shuffle' => (bool) ($ps['shuffle'] ?? $playback['shuffle'] ?? false),
            'volume' => (int) ($playback['volume'] ?? 50),
            'playbackState' => (string) ($ps['playback_state'] ?? $instance->getStatus()->value),
            'currentTrackId' => (string) ($ps['current_track_id'] ?? ''),
            'currentTitle' => (string) ($ps['current_title'] ?? ''),
            'currentArtist' => (string) ($ps['current_artist'] ?? ''),
            'currentSource' => (string) ($ps['current_source'] ?? ''),
            'playbackPositionMs' => (int) ($ps['playback_position_ms'] ?? 0),
            'durationMs' => (int) ($ps['duration_ms'] ?? 0),
            'decoderBackend' => (string) ($ps['decoder_backend'] ?? ''),
            'decoderStatus' => (string) ($ps['decoder_status'] ?? ''),
            'outputBackend' => (string) ($ps['output_backend'] ?? ''),
            'outputStatus' => (string) ($ps['output_status'] ?? ''),
            'framesProcessed' => (int) ($ps['frames_processed'] ?? 0),
            'lastStateChangeAt' => (string) ($ps['last_state_change_at'] ?? ''),
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
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add($type, $message);
        }
    }


    /** @param MusicbotQueueItem[] $queue @return array<string, mixed> */
    private function buildMonitoring(MusicbotInstance $instance, array $queue): array
    {
        $payload = $this->sanitizeForTemplate($instance->getRuntimePayload() ?? []);
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

    private function jobTypeForAction(string $action): string
    {
        return $action === 'status' ? 'musicbot.status' : 'musicbot.service.action';
    }
}
