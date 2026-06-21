<?php

declare(strict_types=1);

namespace App\Module\Musicbot\UI\Controller\Customer;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotPlaybackCommandService;
use App\Module\Musicbot\Application\MusicbotPlaylistService;
use App\Module\Musicbot\Application\MusicbotPluginService;
use App\Module\Musicbot\Application\MusicbotQueueService;
use App\Module\Musicbot\Application\MusicbotRuntimeEventService;
use App\Module\Musicbot\Application\MusicbotStreamService;
use App\Module\Musicbot\Application\MusicbotTrackService;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotPlaylistVisibility;
use App\Module\Musicbot\Domain\Enum\MusicbotRepeatMode;
use App\Module\Musicbot\Domain\Entity\MusicbotQueueItem;
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
        private readonly AgentJobDispatcher $jobDispatcher,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'customer_musicbot_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $instances = $this->instanceRepository->findByCustomer($customer);

        return new Response($this->twig->render('customer/musicbot/index.html.twig', [
            'activeNav' => 'musicbots',
            'instances' => $this->buildIndexRows($instances),
        ]));
    }

    #[Route(path: '/{id}', name: 'customer_musicbot_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($id, $customer);
        $queue = $this->queueItemRepository->findQueueForInstanceOrdered($instance);

        $streamSettings = $this->streamService->getOrCreateSettings($instance);

        return new Response($this->twig->render('customer/musicbot/show.html.twig', [
            'activeNav' => 'musicbots',
            'instance' => $instance,
            'connections' => $this->connectionRepository->findBy(['musicbotInstance' => $instance], ['id' => 'ASC']),
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
        ]));
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
            return new Response('Track file is required.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $track = $this->trackService->uploadTrack($customer, $file, (string) $request->request->get('title', ''), (string) $request->request->get('artist', ''));
        } catch (\Throwable $e) {
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
        $this->runtimeEventService->record($instance, 'track.uploaded', 'info', 'Track uploaded.', ['track_id' => $track->getId(), 'sha256' => $track->getSha256()]);
        $this->auditLogger->log($customer, 'musicbot.track_uploaded', ['track_id' => $track->getId(), 'instance_id' => $id]);
        $this->entityManager->flush();
        $this->flash($request, 'success', 'Aktion erfolgreich ausgeführt.');

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

        return $queue[0]->getTrack() ?? null;
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
        $payload = $instance->getRuntimePayload() ?? [];
        $playback = is_array($payload['playback'] ?? null) ? $payload['playback'] : [];

        return [
            'state' => $instance->getStatus()->value,
            'lastError' => $payload['last_error'] ?? $payload['error'] ?? null,
            'runtimePayload' => $payload,
            'currentTrack' => $this->resolveCurrentTrack($queue),
            'queueLength' => count($queue),
            'repeatMode' => (string) ($playback['repeat_mode'] ?? $playback['repeat'] ?? 'off'),
            'shuffle' => (bool) ($playback['shuffle'] ?? false),
            'volume' => (int) ($playback['volume'] ?? 50),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function buildPlatformStatus(MusicbotInstance $instance): array
    {
        $status = [
            'teamspeak' => ['enabled' => false, 'status' => 'missing', 'lastError' => null, 'profile' => 'ts3', 'backend' => 'ts3_client_compatible', 'capability_status' => 'client_backend_required'],
            'discord' => ['enabled' => false, 'status' => 'missing', 'lastError' => null],
        ];
        foreach ($this->connectionRepository->findBy(['musicbotInstance' => $instance], ['id' => 'ASC']) as $connection) {
            $platform = $connection->getPlatform()->value;
            $config = $connection->getConnectionConfig();
            $status[$platform] = [
                'enabled' => $connection->isEnabled(),
                'status' => $connection->getStatus()->value,
                'lastError' => $connection->getLastError(),
            ] + ($platform === 'teamspeak' ? [
                'profile' => $connection->getTeamspeakProfile()->value,
                'backend' => $connection->getTeamspeakBackend(),
                'capability_status' => (string) ($config['capability_status'] ?? 'client_backend_required'),
            ] : []);
        }

        return $status;
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
        $payload = $instance->getRuntimePayload() ?? [];
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
