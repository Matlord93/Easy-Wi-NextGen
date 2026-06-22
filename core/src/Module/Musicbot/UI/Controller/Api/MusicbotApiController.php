<?php

declare(strict_types=1);

namespace App\Module\Musicbot\UI\Controller\Api;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotPermissionService;
use App\Module\Musicbot\Application\MusicbotPlanLimitResolver;
use App\Module\Musicbot\Application\MusicbotPlaylistService;
use App\Module\Musicbot\Application\MusicbotPluginService;
use App\Module\Musicbot\Application\MusicbotQueueService;
use App\Module\Musicbot\Application\MusicbotQuotaService;
use App\Module\Musicbot\Application\MusicbotRuntimeEventService;
use App\Module\Musicbot\Application\MusicbotAutoDjService;
use App\Module\Musicbot\Application\MusicbotScheduleService;
use App\Module\Musicbot\Application\MusicbotSecretConfigService;
use App\Module\Musicbot\Application\MusicbotStreamService;
use App\Module\Musicbot\Application\MusicbotWorkflowService;
use App\Module\Musicbot\Domain\Entity\MusicbotStreamSettings;
use App\Module\Musicbot\Domain\Entity\MusicbotWorkflow;
use App\Module\Musicbot\Domain\Enum\MusicbotWorkflowTriggerType;
use App\Repository\MusicbotStreamSettingsRepository;
use App\Module\Musicbot\Application\MusicbotStatusProvider;
use App\Module\Musicbot\Application\MusicbotTrackService;
use App\Module\Musicbot\Application\PluginRegistryService;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotCustomerLimits;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotSchedule;
use App\Module\Musicbot\Domain\Entity\MusicbotPlugin;
use App\Module\Musicbot\Domain\Entity\MusicbotQueueItem;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotInstanceStatus;
use App\Module\Musicbot\Domain\Enum\MusicbotPermission;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use App\Module\Musicbot\Domain\Enum\MusicbotScheduleAction;
use App\Module\Musicbot\Domain\Enum\MusicbotPlaylistVisibility;
use App\Module\Musicbot\Domain\Enum\MusicbotTeamspeakProfile;
use App\Module\Musicbot\Domain\Exception\MusicbotPermissionDeniedException;
use App\Module\Musicbot\Domain\Exception\MusicbotQuotaExceededException;
use App\Repository\AgentRepository;
use App\Repository\MusicbotConnectionRepository;
use App\Repository\MusicbotCustomerLimitsRepository;
use App\Repository\MusicbotAutoDjSettingsRepository;
use App\Repository\MusicbotScheduleRepository;
use App\Repository\MusicbotWorkflowExecutionRepository;
use App\Repository\MusicbotWorkflowRepository;
use App\Repository\MusicbotInstanceRepository;
use App\Repository\MusicbotPlaylistRepository;
use App\Repository\MusicbotPluginRepository;
use App\Repository\MusicbotQueueItemRepository;
use App\Repository\MusicbotTrackRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class MusicbotApiController
{
    private const PLAYBACK_ACTIONS = ['play', 'pause', 'resume', 'stop', 'skip', 'volume', 'shuffle', 'repeat'];

    public function __construct(
        private readonly MusicbotStatusProvider $statusProvider,
        private readonly MusicbotTrackService $trackService,
        private readonly MusicbotPlaylistService $playlistService,
        private readonly MusicbotQueueService $queueService,
        private readonly MusicbotPluginService $pluginService,
        private readonly PluginRegistryService $pluginRegistryService,
        private readonly MusicbotRuntimeEventService $runtimeEventService,
        private readonly MusicbotAutoDjService $autoDjService,
        private readonly MusicbotAutoDjSettingsRepository $autoDjSettingsRepository,
        private readonly MusicbotScheduleService $scheduleService,
        private readonly MusicbotScheduleRepository $scheduleRepository,
        private readonly MusicbotStreamService $streamService,
        private readonly MusicbotStreamSettingsRepository $streamSettingsRepository,
        private readonly MusicbotWorkflowService $workflowService,
        private readonly MusicbotWorkflowRepository $workflowRepository,
        private readonly MusicbotWorkflowExecutionRepository $executionRepository,
        private readonly MusicbotSecretConfigService $secretConfigService,
        private readonly MusicbotQuotaService $quotaService,
        private readonly MusicbotPermissionService $permissionService,
        private readonly MusicbotPlanLimitResolver $planLimitResolver,
        private readonly MusicbotInstanceRepository $instanceRepository,
        private readonly MusicbotConnectionRepository $connectionRepository,
        private readonly MusicbotQueueItemRepository $queueItemRepository,
        private readonly MusicbotTrackRepository $trackRepository,
        private readonly MusicbotPlaylistRepository $playlistRepository,
        private readonly MusicbotPluginRepository $pluginRepository,
        private readonly MusicbotCustomerLimitsRepository $customerLimitsRepository,
        private readonly UserRepository $userRepository,
        private readonly AgentRepository $agentRepository,
        private readonly AgentJobDispatcher $jobDispatcher,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/api/musicbots/status', name: 'api_musicbot_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'data' => $this->statusProvider->getOverview()]);
    }

    #[Route(path: '/api/v1/customer/musicbots', name: 'api_v1_customer_musicbots', methods: ['GET'])]
    public function customerIndex(Request $request): JsonResponse
    {
        $customer = $this->requireCustomer($request);

        return new JsonResponse([
            'data' => array_map(fn (MusicbotInstance $instance): array => $this->normalizeInstance($instance), $this->instanceRepository->findByCustomer($customer)),
        ]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}', name: 'api_v1_customer_musicbots_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function customerShow(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);

        return new JsonResponse(['data' => $this->normalizeInstanceDetail($instance, $customer)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/queue', name: 'api_v1_customer_musicbots_queue', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function customerQueue(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);

        return new JsonResponse(['data' => $this->normalizeQueue($this->queueItemRepository->findQueueForInstanceOrdered($instance))]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/playback', name: 'api_v1_customer_musicbots_playback', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function customerPlayback(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $payload = $request->toArray();
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        if (!in_array($action, self::PLAYBACK_ACTIONS, true)) {
            return $this->error('Unsupported playback action.', JsonResponse::HTTP_BAD_REQUEST);
        }

        $job = $this->dispatchPlaybackJob($instance, $customer, $action, $payload);
        $this->runtimeEventService->record($instance, 'playback.command', 'info', sprintf('Playback command "%s" dispatched.', $action), ['job_id' => $job->getId(), 'action' => $action]);
        $this->auditLogger->log($customer, sprintf('musicbot.api_playback_%s', $action), [
            'instance_id' => $instance->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => ['job_id' => $job->getId(), 'action' => $action]], JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/queue', name: 'api_v1_customer_musicbots_queue_add', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function customerQueueAdd(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $payload = $request->toArray();
        $trackId = $payload['track_id'] ?? null;
        $track = is_numeric($trackId) ? $this->trackRepository->findOneForCustomer((int) $trackId, $customer) : null;
        if (!$track instanceof MusicbotTrack) {
            return $this->error('Track not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $this->quotaService->assertCanAddToQueue($customer, $instance);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        $queue = $this->queueItemRepository->findQueueForInstanceOrdered($instance);
        $position = $this->nextQueuePosition($queue);
        $queueItem = new MusicbotQueueItem($instance, $track, $position, $customer);
        $this->entityManager->persist($queueItem);
        $this->entityManager->flush();
        $job = $this->dispatchQueueSyncJob($instance, $customer);
        $this->runtimeEventService->record($instance, 'queue.updated', 'info', 'Track added to queue.', ['queue_item_id' => $queueItem->getId(), 'track_id' => $track->getId(), 'job_id' => $job->getId()]);
        $this->auditLogger->log($customer, 'musicbot.api_queue_added', [
            'instance_id' => $instance->getId(),
            'queue_item_id' => $queueItem->getId(),
            'track_id' => $track->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->normalizeQueueItem($queueItem), 'job_id' => $job->getId()], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/queue/{queueItemId}', name: 'api_v1_customer_musicbots_queue_delete', requirements: ['id' => '\\d+', 'queueItemId' => '\\d+'], methods: ['DELETE'])]
    public function customerQueueDelete(Request $request, int $id, int $queueItemId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $queueItem = $this->queueItemRepository->findOneForCustomer($queueItemId, $customer);
        if (!$queueItem instanceof MusicbotQueueItem || $queueItem->getInstance() !== $instance) {
            return $this->error('Queue item not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($queueItem);
        $this->entityManager->flush();
        $job = $this->dispatchQueueSyncJob($instance, $customer);
        $this->runtimeEventService->record($instance, 'queue.updated', 'info', 'Track removed from queue.', ['queue_item_id' => $queueItemId, 'job_id' => $job->getId()]);
        $this->auditLogger->log($customer, 'musicbot.api_queue_removed', [
            'instance_id' => $instance->getId(),
            'queue_item_id' => $queueItemId,
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => ['deleted' => true, 'job_id' => $job->getId()]]);
    }


    #[Route(path: '/api/v1/customer/musicbots/{id}/tracks', name: 'api_v1_customer_musicbots_tracks', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function customerTracks(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $this->findCustomerInstance($id, $customer);

        return new JsonResponse(['data' => array_map(fn (MusicbotTrack $track): array => $this->normalizeTrack($track), $this->trackService->libraryForCustomer($customer))]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/tracks', name: 'api_v1_customer_musicbots_track_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function customerTrackUpload(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $file = $request->files->get('track_file');
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return $this->error('track_file is required.', JsonResponse::HTTP_BAD_REQUEST);
        }
        try {
            $track = $this->trackService->uploadTrack($customer, $file, (string) $request->request->get('title', ''), (string) $request->request->get('artist', ''));
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
        }
        $this->runtimeEventService->record($instance, 'track.uploaded', 'info', 'Track uploaded.', ['track_id' => $track->getId(), 'sha256' => $track->getSha256()]);
        $this->auditLogger->log($customer, 'musicbot.api_track_uploaded', ['instance_id' => $id, 'track_id' => $track->getId()]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->normalizeTrack($track)], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/tracks/{trackId}', name: 'api_v1_customer_musicbots_track_delete', requirements: ['id' => '\d+', 'trackId' => '\d+'], methods: ['DELETE'])]
    public function customerTrackDelete(Request $request, int $id, int $trackId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $track = $this->trackService->findTrackForCustomer($trackId, $customer);
        if (!$track instanceof MusicbotTrack) { return $this->error('Track not found.', JsonResponse::HTTP_NOT_FOUND); }
        $this->trackService->deleteTrack($customer, $track);
        $this->runtimeEventService->record($instance, 'track.deleted', 'info', 'Track deleted.', ['track_id' => $trackId]);
        $this->auditLogger->log($customer, 'musicbot.api_track_deleted', ['instance_id' => $id, 'track_id' => $trackId]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/playlists', name: 'api_v1_customer_musicbots_playlists', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function customerPlaylists(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $this->findCustomerInstance($id, $customer);

        return new JsonResponse(['data' => array_map(fn ($playlist): array => $this->normalizePlaylist($customer, $playlist), $this->playlistService->playlistsForCustomer($customer))]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/playlists', name: 'api_v1_customer_musicbots_playlist_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function customerPlaylistCreate(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $payload = $request->toArray();
        $visibility = MusicbotPlaylistVisibility::tryFrom((string) ($payload['visibility'] ?? 'private')) ?? MusicbotPlaylistVisibility::Private;
        try {
            $playlist = $this->playlistService->createPlaylist($customer, (string) ($payload['name'] ?? ''), $instance, $visibility);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
        }
        $this->runtimeEventService->record($instance, 'playlist.created', 'info', 'Playlist created.', ['playlist_id' => $playlist->getId()]);
        $this->auditLogger->log($customer, 'musicbot.api_playlist_created', ['instance_id' => $id, 'playlist_id' => $playlist->getId()]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->normalizePlaylist($customer, $playlist)], JsonResponse::HTTP_CREATED);
    }


    #[Route(path: '/api/v1/customer/musicbots/{id}/playlists/{playlistId}', name: 'api_v1_customer_musicbots_playlist_update', requirements: ['id' => '\d+', 'playlistId' => '\d+'], methods: ['PATCH'])]
    public function customerPlaylistUpdate(Request $request, int $id, int $playlistId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $playlist = $this->playlistService->findPlaylistForCustomer($playlistId, $customer);
        if (!$playlist instanceof \App\Module\Musicbot\Domain\Entity\MusicbotPlaylist) { return $this->error('Playlist not found.', JsonResponse::HTTP_NOT_FOUND); }
        $payload = $request->toArray();
        $visibility = MusicbotPlaylistVisibility::tryFrom((string) ($payload['visibility'] ?? $playlist->getVisibility()->value)) ?? $playlist->getVisibility();
        $this->playlistService->updatePlaylist($customer, $playlist, (string) ($payload['name'] ?? $playlist->getName()), $visibility);
        $this->runtimeEventService->record($instance, 'playlist.updated', 'info', 'Playlist updated.', ['playlist_id' => $playlistId]);
        $this->auditLogger->log($customer, 'musicbot.api_playlist_updated', ['instance_id' => $id, 'playlist_id' => $playlistId]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->normalizePlaylist($customer, $playlist)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/playlists/{playlistId}', name: 'api_v1_customer_musicbots_playlist_delete', requirements: ['id' => '\d+', 'playlistId' => '\d+'], methods: ['DELETE'])]
    public function customerPlaylistDelete(Request $request, int $id, int $playlistId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $playlist = $this->playlistService->findPlaylistForCustomer($playlistId, $customer);
        if (!$playlist instanceof \App\Module\Musicbot\Domain\Entity\MusicbotPlaylist) { return $this->error('Playlist not found.', JsonResponse::HTTP_NOT_FOUND); }
        $this->playlistService->deletePlaylist($customer, $playlist);
        $this->runtimeEventService->record($instance, 'playlist.deleted', 'info', 'Playlist deleted.', ['playlist_id' => $playlistId]);
        $this->auditLogger->log($customer, 'musicbot.api_playlist_deleted', ['instance_id' => $id, 'playlist_id' => $playlistId]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/playlists/{playlistId}/tracks', name: 'api_v1_customer_musicbots_playlist_track_add', requirements: ['id' => '\d+', 'playlistId' => '\d+'], methods: ['POST'])]
    public function customerPlaylistTrackAdd(Request $request, int $id, int $playlistId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $this->findCustomerInstance($id, $customer);
        $payload = $request->toArray();
        $playlist = $this->playlistService->findPlaylistForCustomer($playlistId, $customer);
        $track = isset($payload['track_id']) && is_numeric($payload['track_id']) ? $this->trackService->findTrackForCustomer((int) $payload['track_id'], $customer) : null;
        if (!$playlist instanceof \App\Module\Musicbot\Domain\Entity\MusicbotPlaylist || !$track instanceof MusicbotTrack) { return $this->error('Playlist or track not found.', JsonResponse::HTTP_NOT_FOUND); }
        $item = $this->playlistService->addTrack($customer, $playlist, $track);
        $this->auditLogger->log($customer, 'musicbot.api_playlist_track_added', ['instance_id' => $id, 'playlist_id' => $playlistId, 'track_id' => $track->getId(), 'playlist_item_id' => $item->getId()]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->normalizePlaylistItem($item)], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/playlists/items/{itemId}', name: 'api_v1_customer_musicbots_playlist_track_remove', requirements: ['id' => '\d+', 'itemId' => '\d+'], methods: ['DELETE'])]
    public function customerPlaylistTrackRemove(Request $request, int $id, int $itemId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $this->findCustomerInstance($id, $customer);
        $item = $this->playlistService->findPlaylistItemForCustomer($itemId, $customer);
        if (!$item instanceof \App\Module\Musicbot\Domain\Entity\MusicbotPlaylistItem) { return $this->error('Playlist item not found.', JsonResponse::HTTP_NOT_FOUND); }
        $playlistId = $item->getPlaylist()->getId();
        $this->playlistService->removeItem($customer, $item);
        $this->auditLogger->log($customer, 'musicbot.api_playlist_track_removed', ['instance_id' => $id, 'playlist_id' => $playlistId, 'playlist_item_id' => $itemId]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/playlists/{playlistId}/queue', name: 'api_v1_customer_musicbots_playlist_queue', requirements: ['id' => '\d+', 'playlistId' => '\d+'], methods: ['POST'])]
    public function customerPlaylistQueue(Request $request, int $id, int $playlistId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $playlist = $this->playlistService->findPlaylistForCustomer($playlistId, $customer);
        if (!$playlist instanceof \App\Module\Musicbot\Domain\Entity\MusicbotPlaylist) { return $this->error('Playlist not found.', JsonResponse::HTTP_NOT_FOUND); }
        $items = $this->playlistService->loadPlaylistToQueue($customer, $playlist, $instance);
        $this->runtimeEventService->record($instance, 'queue.updated', 'info', 'Playlist loaded into queue.', ['playlist_id' => $playlistId, 'queued_tracks' => count($items)]);
        $this->auditLogger->log($customer, 'musicbot.api_playlist_queued', ['instance_id' => $id, 'playlist_id' => $playlistId, 'queued_tracks' => count($items)]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => ['queued_tracks' => count($items)]]);
    }



    #[Route(path: '/api/v1/customer/musicbots/{id}/plugins', name: 'api_v1_customer_musicbots_plugins', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function customerPlugins(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);

        return new JsonResponse(['data' => [
            'available' => array_map(fn ($manifest): array => $manifest->toArray(), $this->pluginRegistryService->listManifests()),
            'assigned' => array_map(fn (MusicbotPlugin $plugin): array => $this->normalizePlugin($plugin), $this->pluginService->pluginsForInstance($customer, $instance)),
        ]]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/plugins', name: 'api_v1_customer_musicbots_plugin_assign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function customerPluginAssign(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $payload = $request->toArray();
        try {
            $plugin = $this->pluginService->assignPlugin($customer, $instance, (string) ($payload['identifier'] ?? ''));
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
        }
        $this->runtimeEventService->record($instance, 'plugin.changed', 'info', 'Plugin assigned.', ['plugin_id' => $plugin->getId(), 'identifier' => $plugin->getIdentifier()]);
        $this->auditLogger->log($customer, 'musicbot.api_plugin_assigned', ['instance_id' => $id, 'plugin_id' => $plugin->getId(), 'identifier' => $plugin->getIdentifier()]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->normalizePlugin($plugin)], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/plugins/{pluginId}', name: 'api_v1_customer_musicbots_plugin_update', requirements: ['id' => '\d+', 'pluginId' => '\d+'], methods: ['PATCH'])]
    public function customerPluginUpdate(Request $request, int $id, int $pluginId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $plugin = $this->pluginService->findPluginForCustomer($pluginId, $customer);
        if (!$plugin instanceof MusicbotPlugin || $plugin->getInstance() !== $instance) {
            return $this->error('Plugin not found.', JsonResponse::HTTP_NOT_FOUND);
        }
        $payload = $request->toArray();
        if (array_key_exists('enabled', $payload)) {
            $this->pluginService->setEnabled($customer, $plugin, (bool) $payload['enabled']);
        }
        if (array_key_exists('config', $payload)) {
            if (!is_array($payload['config'])) {
                return $this->error('Plugin config must be an object.', JsonResponse::HTTP_BAD_REQUEST);
            }
            try {
                $this->pluginService->saveConfig($customer, $plugin, $payload['config']);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
            }
        }
        $this->runtimeEventService->record($instance, 'plugin.changed', 'info', 'Plugin updated.', ['plugin_id' => $plugin->getId(), 'identifier' => $plugin->getIdentifier(), 'enabled' => $plugin->isEnabled()]);
        $this->auditLogger->log($customer, 'musicbot.api_plugin_updated', ['instance_id' => $id, 'plugin_id' => $plugin->getId(), 'enabled' => $plugin->isEnabled()]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->normalizePlugin($plugin)]);
    }

    #[Route(path: '/api/v1/admin/musicbot-plugins', name: 'api_v1_admin_musicbot_plugins', methods: ['GET'])]
    public function adminPlugins(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        return new JsonResponse(['data' => [
            'manifests' => array_map(fn ($manifest): array => $manifest->toArray(), $this->pluginRegistryService->listManifests()),
            'assignments' => array_map(fn (MusicbotPlugin $plugin): array => $this->normalizePlugin($plugin), $this->pluginRepository->findBy([], ['identifier' => 'ASC'])),
        ]]);
    }


    #[Route(path: '/api/v1/admin/musicbots/{id}/plugins', name: 'api_v1_admin_musicbots_plugin_assign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function adminPluginAssign(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $payload = $request->toArray();
        try {
            $plugin = $this->pluginService->assignPlugin($instance->getCustomer(), $instance, (string) ($payload['identifier'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
        }
        $this->runtimeEventService->record($instance, 'plugin.changed', 'info', 'Plugin assigned by admin.', ['plugin_id' => $plugin->getId(), 'identifier' => $plugin->getIdentifier()]);
        $this->auditLogger->log($actor, 'musicbot.api_admin_plugin_assigned', ['instance_id' => $id, 'plugin_id' => $plugin->getId(), 'identifier' => $plugin->getIdentifier()]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->normalizePlugin($plugin)], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/v1/admin/musicbot-plugins/{pluginId}', name: 'api_v1_admin_musicbot_plugin_update', requirements: ['pluginId' => '\d+'], methods: ['PATCH'])]
    public function adminPluginUpdate(Request $request, int $pluginId): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $plugin = $this->pluginRepository->find($pluginId);
        if (!$plugin instanceof MusicbotPlugin || !$plugin->getCustomer() instanceof User) {
            return $this->error('Plugin not found.', JsonResponse::HTTP_NOT_FOUND);
        }
        $payload = $request->toArray();
        if (array_key_exists('enabled', $payload)) {
            $this->pluginService->setEnabled($plugin->getCustomer(), $plugin, (bool) $payload['enabled']);
        }
        if (array_key_exists('config', $payload)) {
            if (!is_array($payload['config'])) {
                return $this->error('Plugin config must be an object.', JsonResponse::HTTP_BAD_REQUEST);
            }
            try {
                $this->pluginService->saveConfig($plugin->getCustomer(), $plugin, $payload['config']);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
            }
        }
        if ($plugin->getInstance() instanceof MusicbotInstance) {
            $this->runtimeEventService->record($plugin->getInstance(), 'plugin.changed', 'info', 'Plugin updated by admin.', ['plugin_id' => $plugin->getId(), 'identifier' => $plugin->getIdentifier(), 'enabled' => $plugin->isEnabled()]);
        }
        $this->auditLogger->log($actor, 'musicbot.api_admin_plugin_updated', ['plugin_id' => $plugin->getId(), 'enabled' => $plugin->isEnabled()]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->normalizePlugin($plugin)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/logs', name: 'api_v1_customer_musicbots_logs', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function customerLogs(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);

        return new JsonResponse(['data' => array_map(fn ($event): array => $this->normalizeRuntimeEvent($event), $this->runtimeEventService->latestForInstance($instance, 100))]);
    }

    #[Route(path: '/api/v1/admin/musicbots/{id}/logs', name: 'api_v1_admin_musicbots_logs', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function adminLogs(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);

        return new JsonResponse(['data' => array_map(fn ($event): array => $this->normalizeRuntimeEvent($event), $this->runtimeEventService->latestForInstance($instance, 200))]);
    }

    // -------------------------------------------------------------------------
    // Connection Secrets Management
    // -------------------------------------------------------------------------

    /**
     * Get masked secrets for a connection (admin only).
     * Never returns plaintext — only shows which keys are set.
     */
    #[Route(path: '/api/v1/admin/musicbots/{id}/connections/{connId}/secrets', name: 'api_v1_admin_musicbots_connection_secrets_show', requirements: ['id' => '\\d+', 'connId' => '\\d+'], methods: ['GET'])]
    public function adminConnectionSecretsShow(Request $request, int $id, int $connId): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $connection = $this->findConnectionForInstance($connId, $instance);

        return new JsonResponse([
            'data' => [
                'connection_id' => $connection->getId(),
                'platform' => $connection->getPlatform()->value,
                'secrets' => $this->secretConfigService->normalizeForApi($connection->getSecretConfig()),
            ],
        ]);
    }

    /**
     * Set or rotate secrets for a connection (admin only).
     *
     * Body example: {"bot_token": "new-token", "server_password": ""}
     * - Non-empty value → encrypt and store.
     * - Empty string or "********" → keep existing encrypted value unchanged.
     * The response never returns plaintext; only masked keys.
     */
    #[Route(path: '/api/v1/admin/musicbots/{id}/connections/{connId}/secrets', name: 'api_v1_admin_musicbots_connection_secrets_update', requirements: ['id' => '\\d+', 'connId' => '\\d+'], methods: ['PATCH'])]
    public function adminConnectionSecretsUpdate(Request $request, int $id, int $connId): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $connection = $this->findConnectionForInstance($connId, $instance);
        $payload = $request->toArray();

        $allowed = MusicbotSecretConfigService::SECRET_KEYS;
        $updates = array_filter(
            $payload,
            static fn (string $key): bool => in_array($key, $allowed, true),
            ARRAY_FILTER_USE_KEY,
        );

        if ($updates === []) {
            return $this->error('No valid secret keys provided. Allowed: ' . implode(', ', $allowed), JsonResponse::HTTP_BAD_REQUEST);
        }

        $connection->setSecretConfig(
            $this->secretConfigService->mergeSecretUpdates($connection->getSecretConfig(), $updates),
        );
        $this->entityManager->flush();

        $this->runtimeEventService->record($instance, 'connector.secrets.updated', 'info', 'Connection secrets updated.', ['platform' => $connection->getPlatform()->value, 'keys_updated' => array_keys($updates)]);
        $this->auditLogger->log($actor, 'musicbot.api_admin_connection_secrets_updated', ['instance_id' => $id, 'connection_id' => $connId, 'platform' => $connection->getPlatform()->value, 'keys_updated' => array_keys($updates)]);
        $this->entityManager->flush();

        return new JsonResponse([
            'data' => [
                'connection_id' => $connection->getId(),
                'platform' => $connection->getPlatform()->value,
                'secrets' => $this->secretConfigService->normalizeForApi($connection->getSecretConfig()),
            ],
        ]);
    }

    /**
     * Rotate a specific secret key (generates a fresh ciphertext via new random nonce).
     * Body: {"key": "bot_token", "value": "new-plaintext-value"}
     */
    #[Route(path: '/api/v1/admin/musicbots/{id}/connections/{connId}/secrets/rotate', name: 'api_v1_admin_musicbots_connection_secrets_rotate', requirements: ['id' => '\\d+', 'connId' => '\\d+'], methods: ['POST'])]
    public function adminConnectionSecretsRotate(Request $request, int $id, int $connId): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $connection = $this->findConnectionForInstance($connId, $instance);
        $payload = $request->toArray();

        $key = trim((string) ($payload['key'] ?? ''));
        $value = (string) ($payload['value'] ?? '');
        if (!in_array($key, MusicbotSecretConfigService::SECRET_KEYS, true)) {
            return $this->error('Invalid secret key. Allowed: ' . implode(', ', MusicbotSecretConfigService::SECRET_KEYS), JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($value === '') {
            return $this->error('A non-empty value is required for rotation.', JsonResponse::HTTP_BAD_REQUEST);
        }

        $this->secretConfigService->rotateSecret($connection, $key, $value);
        $this->entityManager->flush();

        $this->runtimeEventService->record($instance, 'connector.secrets.rotated', 'info', 'Connection secret rotated.', ['platform' => $connection->getPlatform()->value, 'key' => $key]);
        $this->auditLogger->log($actor, 'musicbot.api_admin_connection_secret_rotated', ['instance_id' => $id, 'connection_id' => $connId, 'platform' => $connection->getPlatform()->value, 'key' => $key]);
        $this->entityManager->flush();

        return new JsonResponse([
            'data' => [
                'connection_id' => $connection->getId(),
                'rotated_key' => $key,
                'secrets' => $this->secretConfigService->normalizeForApi($connection->getSecretConfig()),
            ],
        ]);
    }

    private function findConnectionForInstance(int $connId, MusicbotInstance $instance): MusicbotConnection
    {
        $connection = $this->connectionRepository->find($connId);
        if (!$connection instanceof MusicbotConnection || $connection->getMusicbotInstance()->getId() !== $instance->getId()) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Connection not found.');
        }

        return $connection;
    }

    #[Route(path: '/api/v1/admin/musicbots', name: 'api_v1_admin_musicbots', methods: ['GET'])]
    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        return new JsonResponse([
            'data' => array_map(fn (MusicbotInstance $instance): array => $this->normalizeInstance($instance), $this->instanceRepository->findBy([], ['updatedAt' => 'DESC'])),
        ]);
    }

    #[Route(path: '/api/v1/admin/musicbots', name: 'api_v1_admin_musicbots_create', methods: ['POST'])]
    public function adminCreate(Request $request): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $payload = $request->toArray();
        $parsed = $this->parseAdminInstancePayload($payload);
        if ($parsed['errors'] !== []) {
            return $this->error(implode(' ', $parsed['errors']), JsonResponse::HTTP_BAD_REQUEST);
        }

        /** @var User $customer */
        $customer = $parsed['customer'];
        /** @var Agent $node */
        $node = $parsed['node'];

        try {
            $this->quotaService->assertCanCreateMusicbot($customer);
            if ($parsed['teamspeak_enabled']) {
                $this->quotaService->assertCanManageTeamspeakConnection($customer);
                $profile = MusicbotTeamspeakProfile::tryFrom((string) ($parsed['teamspeak_config']['profile'] ?? 'ts3'));
                if ($profile === MusicbotTeamspeakProfile::Ts6) {
                    $this->quotaService->assertCanUseTeamspeak6Profile($customer);
                }
            }
            if ($parsed['discord_enabled']) {
                $this->quotaService->assertCanManageDiscordConnection($customer);
            }
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $serviceName = $this->buildServiceName($parsed['name']);
        $instance = new MusicbotInstance(
            $customer,
            $node,
            $parsed['name'],
            $serviceName,
            sprintf('/var/lib/easywi/musicbot/%s', $serviceName),
            $parsed['cpu_limit'],
            $parsed['ram_limit'],
            $parsed['disk_limit'],
        );
        $this->entityManager->persist($instance);
        if ($parsed['teamspeak_enabled']) {
            $this->entityManager->persist(new MusicbotConnection($instance, MusicbotPlatform::Teamspeak, $parsed['teamspeak_config'], $this->secretConfigService->encrypt($parsed['teamspeak_secret_config'])));
        }
        if ($parsed['discord_enabled']) {
            $this->entityManager->persist(new MusicbotConnection($instance, MusicbotPlatform::Discord));
        }
        $this->entityManager->flush();
        $job = $this->dispatchInstanceJob('musicbot.install', $instance, ['connections' => ['teamspeak' => $parsed['teamspeak_enabled'], 'discord' => $parsed['discord_enabled']]]);
        $this->auditLogger->log($actor, 'musicbot.api_admin_created', ['instance_id' => $instance->getId(), 'job_id' => $job->getId()]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->normalizeInstanceDetail($instance, $customer), 'job_id' => $job->getId()], JsonResponse::HTTP_CREATED);
    }

    // -------------------------------------------------------------------------
    // Limits API
    // -------------------------------------------------------------------------

    #[Route(path: '/api/v1/customer/musicbots/limits', name: 'api_v1_customer_musicbots_limits', methods: ['GET'])]
    public function customerLimits(Request $request): JsonResponse
    {
        $customer = $this->requireCustomer($request);

        return new JsonResponse([
            'data' => array_merge(
                $this->quotaService->usageForCustomer($customer),
                ['granted_permissions' => $this->permissionService->getGrantedPermissions($customer)],
            ),
        ]);
    }

    #[Route(path: '/api/v1/admin/musicbots/{id}/limits', name: 'api_v1_admin_musicbots_limits_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function adminLimitsShow(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $customer = $instance->getCustomer();
        $override = $this->customerLimitsRepository->findByCustomer($customer);
        $resolved = $this->planLimitResolver->resolve($customer);

        return new JsonResponse([
            'data' => [
                'customer_id' => $customer->getId(),
                'customer_email' => $customer->getEmail(),
                'resolved' => $resolved->toArray(),
                'overrides' => $this->normalizeCustomerLimitsOverride($override),
                'defaults' => MusicbotPlanLimitResolver::planDefaults(),
                'usage' => $this->quotaService->usageForCustomer($customer),
                'granted_permissions' => $this->permissionService->getGrantedPermissions($customer),
            ],
        ]);
    }

    #[Route(path: '/api/v1/admin/musicbots/{id}/limits', name: 'api_v1_admin_musicbots_limits_update', requirements: ['id' => '\\d+'], methods: ['PATCH'])]
    public function adminLimitsUpdate(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $customer = $instance->getCustomer();
        $payload = $request->toArray();

        $limits = $this->customerLimitsRepository->findByCustomer($customer);
        if (!$limits instanceof MusicbotCustomerLimits) {
            $limits = new MusicbotCustomerLimits($customer);
            $this->entityManager->persist($limits);
        }

        $intFields = [
            'max_musicbots' => 'setMaxMusicbots',
            'max_tracks' => 'setMaxTracks',
            'max_storage_mb' => 'setMaxStorageMb',
            'max_playlists' => 'setMaxPlaylists',
            'max_plugins' => 'setMaxPlugins',
            'max_queue_items' => 'setMaxQueueItems',
            'max_connections' => 'setMaxConnections',
            'max_upload_size_mb' => 'setMaxUploadSizeMb',
        ];
        foreach ($intFields as $field => $setter) {
            if (array_key_exists($field, $payload)) {
                $limits->{$setter}($payload[$field] !== null ? (int) $payload[$field] : null);
            }
        }

        $boolFields = [
            'allow_teamspeak' => 'setAllowTeamspeak',
            'allow_discord' => 'setAllowDiscord',
            'allow_teamspeak6_profile' => 'setAllowTeamspeak6Profile',
            'allow_webradio' => 'setAllowWebradio',
            'allow_plugins' => 'setAllowPlugins',
            'allow_workflows' => 'setAllowWorkflows',
            'allow_scheduler' => 'setAllowScheduler',
        ];
        foreach ($boolFields as $field => $setter) {
            if (array_key_exists($field, $payload)) {
                $limits->{$setter}($payload[$field] !== null ? (bool) $payload[$field] : null);
            }
        }

        if (array_key_exists('granted_permissions', $payload)) {
            if ($payload['granted_permissions'] === null) {
                $limits->setGrantedPermissions(null);
            } elseif (is_array($payload['granted_permissions'])) {
                $validValues = array_map(static fn (MusicbotPermission $p): string => $p->value, MusicbotPermission::cases());
                $filtered = array_values(array_filter(
                    $payload['granted_permissions'],
                    static fn (mixed $v): bool => is_string($v) && in_array($v, $validValues, true),
                ));
                $limits->setGrantedPermissions($filtered);
            } else {
                return $this->error('granted_permissions must be an array or null.', JsonResponse::HTTP_BAD_REQUEST);
            }
        }

        $this->entityManager->flush();
        $this->auditLogger->log($actor, 'musicbot.api_admin_limits_updated', ['instance_id' => $id, 'customer_id' => $customer->getId()]);

        return new JsonResponse([
            'data' => [
                'customer_id' => $customer->getId(),
                'resolved' => $this->planLimitResolver->resolve($customer)->toArray(),
                'overrides' => $this->normalizeCustomerLimitsOverride($limits),
            ],
        ]);
    }

    #[Route(path: '/api/v1/admin/musicbots/{id}', name: 'api_v1_admin_musicbots_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function adminShow(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);

        return new JsonResponse(['data' => $this->normalizeInstanceDetail($instance, $instance->getCustomer())]);
    }

    #[Route(path: '/api/v1/admin/musicbots/{id}', name: 'api_v1_admin_musicbots_update', requirements: ['id' => '\\d+'], methods: ['PATCH'])]
    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $payload = $request->toArray();
        if (isset($payload['name']) && trim((string) $payload['name']) !== '') {
            $instance->setName(trim((string) $payload['name']));
        }
        foreach (['cpu_limit' => 'setCpuLimit', 'ram_limit' => 'setRamLimit', 'disk_limit' => 'setDiskLimit'] as $field => $setter) {
            if (array_key_exists($field, $payload) && is_numeric($payload[$field])) {
                $instance->{$setter}((int) $payload[$field]);
            }
        }
        if (isset($payload['status']) && is_string($payload['status'])) {
            $status = MusicbotInstanceStatus::tryFrom($payload['status']);
            if ($status !== null) {
                $instance->setStatus($status);
            }
        }
        $this->auditLogger->log($actor, 'musicbot.api_admin_updated', ['instance_id' => $instance->getId()]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->normalizeInstanceDetail($instance, $instance->getCustomer())]);
    }

    #[Route(path: '/api/v1/admin/musicbots/{id}', name: 'api_v1_admin_musicbots_delete', requirements: ['id' => '\\d+'], methods: ['DELETE'])]
    public function adminDelete(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $job = $this->dispatchInstanceJob('musicbot.uninstall', $instance, ['delete_data' => true]);
        $this->auditLogger->log($actor, 'musicbot.api_admin_deleted', ['instance_id' => $instance->getId(), 'job_id' => $job->getId()]);
        $this->entityManager->remove($instance);
        $this->entityManager->flush();

        return new JsonResponse(['data' => ['deleted' => true, 'job_id' => $job->getId()]]);
    }

    // ── Customer schedule endpoints ──────────────────────────────────────────

    #[Route(path: '/api/v1/customer/musicbots/{id}/schedules', name: 'api_v1_customer_musicbot_schedules_index', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function customerScheduleIndex(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);

        return new JsonResponse([
            'data' => array_map(fn (MusicbotSchedule $s): array => $this->scheduleService->normalize($s), $this->scheduleRepository->findByCustomerAndInstance($customer, $instance)),
        ]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/schedules', name: 'api_v1_customer_musicbot_schedules_create', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function customerScheduleCreate(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $payload = $request->toArray();

        $action = MusicbotScheduleAction::tryFrom((string) ($payload['action'] ?? ''));
        if ($action === null) {
            return $this->error('action is required and must be a valid schedule action.', JsonResponse::HTTP_BAD_REQUEST);
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->error('name is required.', JsonResponse::HTTP_BAD_REQUEST);
        }
        $cronExpression = trim((string) ($payload['cron_expression'] ?? ''));
        if ($cronExpression === '') {
            return $this->error('cron_expression is required.', JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $schedule = $this->scheduleService->create(
                $customer,
                $instance,
                $name,
                $cronExpression,
                trim((string) ($payload['timezone'] ?? 'UTC')),
                (bool) ($payload['enabled'] ?? true),
                $action,
                is_array($payload['payload'] ?? null) ? $payload['payload'] : [],
            );
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['data' => $this->scheduleService->normalize($schedule)], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/schedules/{scheduleId}', name: 'api_v1_customer_musicbot_schedules_show', requirements: ['id' => '\\d+', 'scheduleId' => '\\d+'], methods: ['GET'])]
    public function customerScheduleShow(Request $request, int $id, int $scheduleId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $schedule = $this->findCustomerSchedule($scheduleId, $customer, $instance);

        return new JsonResponse(['data' => $this->scheduleService->normalize($schedule)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/schedules/{scheduleId}', name: 'api_v1_customer_musicbot_schedules_update', requirements: ['id' => '\\d+', 'scheduleId' => '\\d+'], methods: ['PATCH'])]
    public function customerScheduleUpdate(Request $request, int $id, int $scheduleId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $schedule = $this->findCustomerSchedule($scheduleId, $customer, $instance);

        try {
            $schedule = $this->scheduleService->update($customer, $schedule, $request->toArray());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['data' => $this->scheduleService->normalize($schedule)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/schedules/{scheduleId}', name: 'api_v1_customer_musicbot_schedules_delete', requirements: ['id' => '\\d+', 'scheduleId' => '\\d+'], methods: ['DELETE'])]
    public function customerScheduleDelete(Request $request, int $id, int $scheduleId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $schedule = $this->findCustomerSchedule($scheduleId, $customer, $instance);
        $this->scheduleService->delete($customer, $schedule);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/schedules/{scheduleId}/toggle', name: 'api_v1_customer_musicbot_schedules_toggle', requirements: ['id' => '\\d+', 'scheduleId' => '\\d+'], methods: ['POST'])]
    public function customerScheduleToggle(Request $request, int $id, int $scheduleId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $schedule = $this->findCustomerSchedule($scheduleId, $customer, $instance);
        $payload = $request->toArray();

        $enabled = array_key_exists('enabled', $payload) ? (bool) $payload['enabled'] : !$schedule->isEnabled();
        $this->scheduleService->toggle($customer, $schedule, $enabled);

        return new JsonResponse(['data' => $this->scheduleService->normalize($schedule)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/schedules/{scheduleId}/test', name: 'api_v1_customer_musicbot_schedules_test', requirements: ['id' => '\\d+', 'scheduleId' => '\\d+'], methods: ['POST'])]
    public function customerScheduleTest(Request $request, int $id, int $scheduleId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $schedule = $this->findCustomerSchedule($scheduleId, $customer, $instance);

        try {
            $job = $this->scheduleService->runNow($customer, $schedule);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => ['job_id' => $job->getId(), 'schedule' => $this->scheduleService->normalize($schedule)]]);
    }

    // ── Admin schedule endpoints ─────────────────────────────────────────────

    #[Route(path: '/api/v1/admin/musicbot-schedules', name: 'api_v1_admin_musicbot_schedules', methods: ['GET'])]
    public function adminScheduleIndex(Request $request): JsonResponse
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

        return new JsonResponse([
            'data' => array_map(fn (MusicbotSchedule $s): array => array_merge($this->scheduleService->normalize($s), [
                'customer' => ['id' => $s->getCustomer()->getId(), 'email' => $s->getCustomer()->getEmail()],
            ]), $schedules),
            'total' => count($schedules),
        ]);
    }

    #[Route(path: '/api/v1/admin/musicbot-schedules/{id}', name: 'api_v1_admin_musicbot_schedules_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function adminScheduleShow(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $schedule = $this->findAdminSchedule($id);

        return new JsonResponse(['data' => array_merge($this->scheduleService->normalize($schedule), [
            'customer' => ['id' => $schedule->getCustomer()->getId(), 'email' => $schedule->getCustomer()->getEmail()],
        ])]);
    }

    #[Route(path: '/api/v1/admin/musicbot-schedules/{id}/disable', name: 'api_v1_admin_musicbot_schedules_disable', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function adminScheduleDisable(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $schedule = $this->findAdminSchedule($id);
        $schedule->setEnabled(false);
        $this->entityManager->flush();
        $this->auditLogger->log($actor, 'musicbot.admin_schedule_disabled', ['schedule_id' => $schedule->getId(), 'customer_id' => $schedule->getCustomer()->getId()]);

        return new JsonResponse(['data' => $this->scheduleService->normalize($schedule)]);
    }

    // ── Customer Auto-DJ endpoints ────────────────────────────────────────────

    #[Route(path: '/api/v1/customer/musicbots/{id}/autodj', name: 'api_v1_customer_musicbot_autodj_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function customerAutoDjShow(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $settings = $this->autoDjService->getOrCreateSettings($instance);

        return new JsonResponse(['data' => $this->autoDjService->normalize($settings)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/autodj', name: 'api_v1_customer_musicbot_autodj_save', requirements: ['id' => '\\d+'], methods: ['PUT', 'PATCH'])]
    public function customerAutoDjSave(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);

        try {
            $settings = $this->autoDjService->saveSettings($customer, $instance, $request->toArray());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return new JsonResponse(['data' => $this->autoDjService->normalize($settings)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/autodj/enable', name: 'api_v1_customer_musicbot_autodj_enable', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function customerAutoDjEnable(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $settings = $this->autoDjService->enable($customer, $instance);

        return new JsonResponse(['data' => $this->autoDjService->normalize($settings)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/autodj/disable', name: 'api_v1_customer_musicbot_autodj_disable', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function customerAutoDjDisable(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $settings = $this->autoDjService->disable($customer, $instance);

        return new JsonResponse(['data' => $this->autoDjService->normalize($settings)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/autodj/trigger', name: 'api_v1_customer_musicbot_autodj_trigger', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function customerAutoDjTrigger(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);

        try {
            $added = $this->autoDjService->trigger($customer, $instance);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $settings = $this->autoDjService->getOrCreateSettings($instance);

        return new JsonResponse([
            'data' => ['tracks_added' => $added, 'settings' => $this->autoDjService->normalize($settings)],
        ]);
    }


    #[Route(path: '/api/v1/customer/musicbots/{id}/autodj/run', name: 'api_v1_customer_musicbot_autodj_run', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function customerAutoDjRun(Request $request, int $id): JsonResponse
    {
        return $this->customerAutoDjTrigger($request, $id);
    }

    #[Route(path: '/api/v1/admin/musicbots/{id}/autodj', name: 'api_v1_admin_musicbot_autodj_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function adminAutoDjShow(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $settings = $this->autoDjService->getOrCreateSettings($instance);

        return new JsonResponse(['data' => $this->autoDjService->normalize($settings)]);
    }

    #[Route(path: '/api/v1/admin/musicbots/{id}/autodj', name: 'api_v1_admin_musicbot_autodj_save', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function adminAutoDjSave(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);

        try {
            $settings = $this->autoDjService->saveSettings($instance->getCustomer(), $instance, $request->toArray());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return new JsonResponse(['data' => $this->autoDjService->normalize($settings)]);
    }

    // ── Customer workflow endpoints ───────────────────────────────────────────

    #[Route(path: '/api/v1/customer/musicbots/{id}/workflows', name: 'api_v1_customer_musicbot_workflows', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function customerWorkflowIndex(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);

        $workflows = $this->workflowRepository->findByInstanceForCustomer($instance, $customer);

        return new JsonResponse(['data' => array_map(fn (MusicbotWorkflow $w): array => $this->workflowService->normalize($w), $workflows)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/workflows', name: 'api_v1_customer_musicbot_workflows_create', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function customerWorkflowCreate(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $payload = $request->toArray();

        $triggerType = MusicbotWorkflowTriggerType::tryFrom((string) ($payload['trigger_type'] ?? ''));
        if ($triggerType === null) {
            return $this->error('Invalid or missing trigger_type.', 422);
        }

        try {
            $workflow = $this->workflowService->create(
                $customer,
                $instance,
                trim((string) ($payload['name'] ?? '')),
                $triggerType,
                is_array($payload['trigger_config'] ?? null) ? $payload['trigger_config'] : [],
                ($payload['description'] ?? null) !== null ? (string) $payload['description'] : null,
                (bool) ($payload['enabled'] ?? true),
                is_array($payload['conditions'] ?? null) ? $payload['conditions'] : [],
                is_array($payload['actions'] ?? null) ? $payload['actions'] : [],
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\App\Module\Musicbot\Domain\Exception\MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), 403);
        }

        return new JsonResponse(['data' => $this->workflowService->normalize($workflow)], 201);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/workflows/{wid}', name: 'api_v1_customer_musicbot_workflows_show', requirements: ['id' => '\\d+', 'wid' => '\\d+'], methods: ['GET'])]
    public function customerWorkflowShow(Request $request, int $id, int $wid): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $workflow = $this->findCustomerWorkflow($wid, $customer, $instance);

        return new JsonResponse(['data' => $this->workflowService->normalize($workflow)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/workflows/{wid}', name: 'api_v1_customer_musicbot_workflows_update', requirements: ['id' => '\\d+', 'wid' => '\\d+'], methods: ['PUT', 'PATCH'])]
    public function customerWorkflowUpdate(Request $request, int $id, int $wid): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $workflow = $this->findCustomerWorkflow($wid, $customer, $instance);

        try {
            $workflow = $this->workflowService->update($customer, $workflow, $request->toArray());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return new JsonResponse(['data' => $this->workflowService->normalize($workflow)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/workflows/{wid}', name: 'api_v1_customer_musicbot_workflows_delete', requirements: ['id' => '\\d+', 'wid' => '\\d+'], methods: ['DELETE'])]
    public function customerWorkflowDelete(Request $request, int $id, int $wid): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $workflow = $this->findCustomerWorkflow($wid, $customer, $instance);
        $this->workflowService->delete($customer, $workflow);

        return new JsonResponse(null, 204);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/workflows/{wid}/toggle', name: 'api_v1_customer_musicbot_workflows_toggle', requirements: ['id' => '\\d+', 'wid' => '\\d+'], methods: ['POST'])]
    public function customerWorkflowToggle(Request $request, int $id, int $wid): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $workflow = $this->findCustomerWorkflow($wid, $customer, $instance);
        $payload = $request->toArray();
        $enabled = (bool) ($payload['enabled'] ?? !$workflow->isEnabled());
        $this->workflowService->toggle($customer, $workflow, $enabled);

        return new JsonResponse(['data' => $this->workflowService->normalize($workflow)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/workflows/{wid}/test', name: 'api_v1_customer_musicbot_workflows_test', requirements: ['id' => '\\d+', 'wid' => '\\d+'], methods: ['POST'])]
    public function customerWorkflowTest(Request $request, int $id, int $wid): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $workflow = $this->findCustomerWorkflow($wid, $customer, $instance);
        $context = is_array($request->toArray()['context'] ?? null) ? $request->toArray()['context'] : [];

        try {
            $execution = $this->workflowService->testRun($customer, $workflow, $context);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return new JsonResponse(['data' => $this->workflowService->normalizeExecution($execution)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/workflows/{wid}/executions', name: 'api_v1_customer_musicbot_workflows_executions', requirements: ['id' => '\\d+', 'wid' => '\\d+'], methods: ['GET'])]
    public function customerWorkflowExecutions(Request $request, int $id, int $wid): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $workflow = $this->findCustomerWorkflow($wid, $customer, $instance);
        $limit = min(100, max(1, (int) ($request->query->get('limit') ?? 50)));

        $executions = $this->executionRepository->findByWorkflow($workflow, $limit);

        return new JsonResponse([
            'data' => array_map(fn (\App\Module\Musicbot\Domain\Entity\MusicbotWorkflowExecution $e): array => $this->workflowService->normalizeExecution($e), $executions),
        ]);
    }

    // ── Customer stream (webradio) endpoints ─────────────────────────────────

    #[Route(path: '/api/v1/customer/musicbots/{id}/stream', name: 'api_v1_customer_musicbot_stream_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function customerStreamShow(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $this->permissionService->assertPermission($customer, MusicbotPermission::WebradioManage);
        $instance = $this->findCustomerInstance($id, $customer);

        $settings = $this->streamService->getOrCreateSettings($instance);

        return new JsonResponse(['data' => $this->streamService->normalize($settings)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/stream', name: 'api_v1_customer_musicbot_stream_update', requirements: ['id' => '\\d+'], methods: ['PUT', 'PATCH'])]
    public function customerStreamUpdate(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $this->permissionService->assertPermission($customer, MusicbotPermission::WebradioManage);
        $instance = $this->findCustomerInstance($id, $customer);

        try {
            $settings = $this->streamService->saveSettings($customer, $instance, $request->toArray());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => $this->streamService->normalize($settings)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/stream/enable', name: 'api_v1_customer_musicbot_stream_enable', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function customerStreamEnable(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $this->permissionService->assertPermission($customer, MusicbotPermission::WebradioManage);
        $instance = $this->findCustomerInstance($id, $customer);

        try {
            $settings = $this->streamService->enable($customer, $instance);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => $this->streamService->normalize($settings)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/stream/disable', name: 'api_v1_customer_musicbot_stream_disable', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function customerStreamDisable(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $this->permissionService->assertPermission($customer, MusicbotPermission::WebradioManage);
        $instance = $this->findCustomerInstance($id, $customer);

        $settings = $this->streamService->disable($customer, $instance);

        return new JsonResponse(['data' => $this->streamService->normalize($settings)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/stream/rotate-token', name: 'api_v1_customer_musicbot_stream_rotate_token', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function customerStreamRotateToken(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $this->permissionService->assertPermission($customer, MusicbotPermission::WebradioManage);
        $instance = $this->findCustomerInstance($id, $customer);

        try {
            $result = $this->streamService->rotateToken($customer, $instance);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'data' => array_merge($this->streamService->normalize($result['settings']), [
                'new_token' => $result['token'],
            ]),
        ]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/stream/status', name: 'api_v1_customer_musicbot_stream_status', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function customerStreamStatus(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $this->permissionService->assertPermission($customer, MusicbotPermission::WebradioManage);
        $instance = $this->findCustomerInstance($id, $customer);

        return new JsonResponse(['data' => $this->streamService->getStatus($instance)]);
    }

    // ── Admin stream endpoints ────────────────────────────────────────────────

    #[Route(path: '/api/v1/admin/musicbots/{id}/stream', name: 'api_v1_admin_musicbot_stream_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function adminStreamShow(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $settings = $this->streamService->getOrCreateSettings($instance);

        return new JsonResponse(['data' => $this->streamService->normalize($settings)]);
    }


    #[Route(path: '/api/v1/admin/musicbots/{id}/stream', name: 'api_v1_admin_musicbot_stream_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function adminStreamUpdate(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);

        try {
            $settings = $this->streamService->saveSettings($instance->getCustomer(), $instance, $request->toArray());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => $this->streamService->normalize($settings)]);
    }

    #[Route(path: '/api/v1/admin/musicbots/{id}/stream/enable', name: 'api_v1_admin_musicbot_stream_enable', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function adminStreamEnable(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);

        try {
            $settings = $this->streamService->enable($instance->getCustomer(), $instance);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => $this->streamService->normalize($settings)]);
    }

    #[Route(path: '/api/v1/admin/musicbots/{id}/stream/disable', name: 'api_v1_admin_musicbot_stream_disable', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function adminStreamDisable(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $settings = $this->streamService->disable($instance->getCustomer(), $instance);

        return new JsonResponse(['data' => $this->streamService->normalize($settings)]);
    }

    #[Route(path: '/api/v1/admin/musicbots/{id}/stream/rotate-token', name: 'api_v1_admin_musicbot_stream_rotate_token', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function adminStreamRotateToken(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);

        try {
            $result = $this->streamService->rotateToken($instance->getCustomer(), $instance);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'data' => array_merge($this->streamService->normalize($result['settings']), [
                'new_token' => $result['token'],
            ]),
        ]);
    }

    #[Route(path: '/api/v1/admin/musicbots/{id}/stream/status', name: 'api_v1_admin_musicbot_stream_status', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function adminStreamStatus(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);

        return new JsonResponse(['data' => $this->streamService->getStatus($instance)]);
    }

    // ── Admin workflow endpoints ──────────────────────────────────────────────

    #[Route(path: '/api/v1/admin/musicbot-workflows', name: 'api_v1_admin_musicbot_workflows', methods: ['GET'])]
    public function adminWorkflowIndex(Request $request): JsonResponse
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

        return new JsonResponse([
            'data' => array_map(fn (MusicbotWorkflow $w): array => array_merge($this->workflowService->normalize($w), [
                'customer' => ['id' => $w->getCustomer()->getId(), 'email' => $w->getCustomer()->getEmail()],
            ]), $workflows),
            'total' => count($workflows),
        ]);
    }

    #[Route(path: '/api/v1/admin/musicbot-workflows/{id}', name: 'api_v1_admin_musicbot_workflows_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function adminWorkflowShow(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $workflow = $this->findAdminWorkflow($id);

        return new JsonResponse(['data' => array_merge($this->workflowService->normalize($workflow), [
            'customer' => ['id' => $workflow->getCustomer()->getId(), 'email' => $workflow->getCustomer()->getEmail()],
        ])]);
    }

    #[Route(path: '/api/v1/admin/musicbot-workflows/{id}/disable', name: 'api_v1_admin_musicbot_workflows_disable', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function adminWorkflowDisable(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $workflow = $this->findAdminWorkflow($id);
        $workflow->setEnabled(false);
        $this->entityManager->flush();
        $this->auditLogger->log($actor, 'musicbot.admin_workflow_disabled', ['workflow_id' => $workflow->getId(), 'customer_id' => $workflow->getCustomer()->getId()]);

        return new JsonResponse(['data' => $this->workflowService->normalize($workflow)]);
    }

    private function findCustomerWorkflow(int $workflowId, User $customer, MusicbotInstance $instance): MusicbotWorkflow
    {
        $workflow = $this->workflowRepository->find($workflowId);
        if (!$workflow instanceof MusicbotWorkflow
            || $workflow->getCustomer()->getId() !== $customer->getId()
            || $workflow->getInstance()->getId() !== $instance->getId()
        ) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Workflow not found.');
        }

        return $workflow;
    }

    private function findAdminWorkflow(int $id): MusicbotWorkflow
    {
        $workflow = $this->workflowRepository->find($id);
        if (!$workflow instanceof MusicbotWorkflow) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Workflow not found.');
        }

        return $workflow;
    }

    private function findCustomerSchedule(int $scheduleId, User $customer, MusicbotInstance $instance): MusicbotSchedule
    {
        $schedule = $this->scheduleRepository->find($scheduleId);
        if (!$schedule instanceof MusicbotSchedule
            || $schedule->getCustomer()->getId() !== $customer->getId()
            || $schedule->getInstance()->getId() !== $instance->getId()
        ) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Schedule not found.');
        }

        return $schedule;
    }

    private function findAdminSchedule(int $id): MusicbotSchedule
    {
        $schedule = $this->scheduleRepository->find($id);
        if (!$schedule instanceof MusicbotSchedule) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Schedule not found.');
        }

        return $schedule;
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('api', 'Unauthorized.');
        }

        return $actor;
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Forbidden.');
        }

        return $actor;
    }

    private function findCustomerInstance(int $id, User $customer): MusicbotInstance
    {
        $instance = $this->instanceRepository->findOneForCustomer($id, $customer);
        if (!$instance instanceof MusicbotInstance) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Musicbot not found.');
        }

        return $instance;
    }

    private function findAdminInstance(int $id): MusicbotInstance
    {
        $instance = $this->instanceRepository->find($id);
        if (!$instance instanceof MusicbotInstance) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Musicbot not found.');
        }

        return $instance;
    }

    /** @return array<string, mixed> */
    private function normalizeInstance(MusicbotInstance $instance): array
    {
        $queue = $this->queueItemRepository->findQueueForInstanceOrdered($instance);
        $currentTrack = $this->resolveCurrentTrack($queue);
        $connections = $this->connectionRepository->findBy(['musicbotInstance' => $instance], ['id' => 'ASC']);

        return [
            'id' => $instance->getId(),
            'name' => $instance->getName(),
            'status' => $instance->getStatus()->value,
            'customer' => ['id' => $instance->getCustomer()->getId(), 'email' => $instance->getCustomer()->getEmail()],
            'node' => ['id' => $instance->getNode()->getId(), 'name' => $instance->getNode()->getName() ?? $instance->getNode()->getId()],
            'service_name' => $instance->getServiceName(),
            'install_path' => $instance->getInstallPath(),
            'limits' => ['cpu' => $instance->getCpuLimit(), 'ram' => $instance->getRamLimit(), 'disk' => $instance->getDiskLimit()],
            'connections' => array_map(fn (MusicbotConnection $connection): array => $this->normalizeConnection($connection), $connections),
            'current_track' => $currentTrack instanceof MusicbotTrack ? $this->normalizeTrack($currentTrack) : null,
            'queue_length' => count($queue),
            'auto_dj_enabled' => ($this->autoDjSettingsRepository->findByInstance($instance)?->isEnabled()) ?? false,
            'stream_enabled' => ($this->streamSettingsRepository->findByInstance($instance)?->isEnabled()) ?? false,
            'stream_ready' => false,
            'stream_url_placeholder' => $this->resolveStreamUrlPlaceholder($instance),
            'created_at' => $instance->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $instance->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeInstanceDetail(MusicbotInstance $instance, User $customer): array
    {
        return array_merge($this->normalizeInstance($instance), [
            'queue' => $this->normalizeQueue($this->queueItemRepository->findQueueForInstanceOrdered($instance)),
            'playlists' => array_map(fn ($playlist): array => ['id' => $playlist->getId(), 'name' => $playlist->getName(), 'visibility' => $playlist->getVisibility()->value], $this->loadPlaylists($customer, $instance)),
            'plugins' => array_map(fn (MusicbotPlugin $plugin): array => $this->normalizePlugin($plugin), $this->pluginService->pluginsForInstance($customer, $instance)),
        ]);
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

    /** @param MusicbotQueueItem[] $queue @return array<int, array<string, mixed>> */
    private function normalizeQueue(array $queue): array
    {
        return array_map(fn (MusicbotQueueItem $item): array => $this->normalizeQueueItem($item), $queue);
    }

    /** @return array<string, mixed> */
    private function normalizeQueueItem(MusicbotQueueItem $item): array
    {
        return [
            'id' => $item->getId(),
            'position' => $item->getPosition(),
            'status' => $item->getStatus(),
            'track' => $this->normalizeTrack($item->getTrack()),
            'requested_by' => $item->getRequestedBy() instanceof User ? ['id' => $item->getRequestedBy()->getId(), 'email' => $item->getRequestedBy()->getEmail()] : null,
            'created_at' => $item->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeTrack(MusicbotTrack $track): array
    {
        return ['id' => $track->getId(), 'title' => $track->getTitle(), 'artist' => $track->getArtist(), 'duration_seconds' => $track->getDurationSeconds(), 'source_type' => $track->getSourceType()->value, 'mime_type' => $track->getMimeType(), 'metadata' => $track->getMetadata()];
    }

    /** @return array<string, mixed> */
    private function normalizeConnection(MusicbotConnection $connection): array
    {
        $secrets = $connection->getSecretConfig();
        $data = [
            'id' => $connection->getId(),
            'platform' => $connection->getPlatform()->value,
            'enabled' => $connection->isEnabled(),
            'status' => $connection->getStatus()->value,
            'last_connected_at' => $connection->getLastConnectedAt()?->format(DATE_ATOM),
            'last_error' => $connection->getLastError(),
            'secrets' => $this->secretConfigService->normalizeForApi($secrets),
        ];
        if ($connection->getPlatform() === MusicbotPlatform::Teamspeak) {
            $data['profile'] = $connection->getTeamspeakProfile()->value;
            $data['backend'] = 'ts3_client_compatible';
            $data['capability_status'] = 'client_backend_required';
        }
        if ($connection->getPlatform() === MusicbotPlatform::Discord) {
            $config = $connection->getConnectionConfig();
            $data['config'] = [
                'application_id' => (string) ($config['application_id'] ?? ''),
                'guild_id' => (string) ($config['guild_id'] ?? ''),
                'voice_channel_id' => (string) ($config['voice_channel_id'] ?? ''),
                'command_mode' => (string) ($config['command_mode'] ?? 'placeholder'),
                'slash_commands_enabled' => (bool) ($config['slash_commands_enabled'] ?? false),
                'reconnect_policy' => (string) ($config['reconnect_policy'] ?? 'manual'),
            ];
            $data['backend'] = 'placeholder';
            $data['capability_status'] = (string) ($config['capability_status'] ?? 'voice_backend_required');
            $data['slash_commands_status'] = 'placeholder';
        }

        return $data;
    }



    /** @return array<string, mixed> */
    private function normalizePlugin(MusicbotPlugin $plugin): array
    {
        return [
            'id' => $plugin->getId(),
            'identifier' => $plugin->getIdentifier(),
            'name' => $plugin->getName(),
            'version' => $plugin->getVersion(),
            'enabled' => $plugin->isEnabled(),
            'permissions' => $plugin->getPermissions(),
            'config' => $plugin->getConfig(),
            'customer_id' => $plugin->getCustomer()?->getId(),
            'instance_id' => $plugin->getInstance()?->getId(),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeRuntimeEvent(\App\Module\Musicbot\Domain\Entity\MusicbotRuntimeEvent $event): array
    {
        return [
            'id' => $event->getId(),
            'type' => $event->getType(),
            'level' => $event->getLevel(),
            'message' => $event->getMessage(),
            'context' => $event->getContext(),
            'created_at' => $event->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizePlaylist(User $customer, \App\Module\Musicbot\Domain\Entity\MusicbotPlaylist $playlist): array
    {
        return ['id' => $playlist->getId(), 'name' => $playlist->getName(), 'visibility' => $playlist->getVisibility()->value, 'items' => array_map(fn ($item): array => $this->normalizePlaylistItem($item), $this->playlistService->itemsForPlaylist($customer, $playlist))];
    }


    /** @return array<string, mixed> */
    private function normalizePlaylistItem(\App\Module\Musicbot\Domain\Entity\MusicbotPlaylistItem $item): array
    {
        return ['id' => $item->getId(), 'position' => $item->getPosition(), 'track' => $this->normalizeTrack($item->getTrack())];
    }

    /** @param MusicbotQueueItem[] $queue */
    private function resolveCurrentTrack(array $queue): ?MusicbotTrack
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

    private function nextQueuePosition(array $queue): int
    {
        $position = 0;
        foreach ($queue as $queueItem) {
            $position = max($position, $queueItem->getPosition());
        }

        return $position + 1;
    }

    /** @param array<string, mixed> $payload */
    private function dispatchPlaybackJob(MusicbotInstance $instance, User $customer, string $action, array $payload): \App\Module\AgentOrchestrator\Domain\Entity\AgentJob
    {
        return $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.playback.action', array_merge($this->baseJobPayload($instance, $customer), ['action' => $action, 'options' => $payload]));
    }

    private function dispatchQueueSyncJob(MusicbotInstance $instance, User $customer): \App\Module\AgentOrchestrator\Domain\Entity\AgentJob
    {
        return $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.queue.sync', array_merge($this->baseJobPayload($instance, $customer), ['queue_length' => count($this->queueItemRepository->findQueueForInstanceOrdered($instance))]));
    }

    /** @param array<string, mixed> $extra */
    private function dispatchInstanceJob(string $type, MusicbotInstance $instance, array $extra): \App\Module\AgentOrchestrator\Domain\Entity\AgentJob
    {
        return $this->jobDispatcher->dispatch($instance->getNode(), $type, array_merge($this->baseJobPayload($instance, $instance->getCustomer()), $extra));
    }

    /** @return array<string, mixed> */
    private function baseJobPayload(MusicbotInstance $instance, User $customer): array
    {
        return ['instance_id' => (string) $instance->getId(), 'customer_id' => (string) $customer->getId(), 'node_id' => $instance->getNode()->getId(), 'service_name' => $instance->getServiceName(), 'install_dir' => $instance->getInstallPath(), 'install_path' => $instance->getInstallPath(), 'config_file_permissions' => '0600'];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function parseAdminInstancePayload(array $payload): array
    {
        $errors = [];
        $customer = isset($payload['customer_id']) && is_numeric($payload['customer_id']) ? $this->userRepository->find((int) $payload['customer_id']) : null;
        if (!$customer instanceof User || $customer->getType() !== UserType::Customer) {
            $errors[] = 'customer_id is required.';
        }
        $nodeId = (string) ($payload['node_id'] ?? '');
        $node = $nodeId !== '' ? $this->agentRepository->find($nodeId) : null;
        if (!$node instanceof Agent) {
            $errors[] = 'node_id is required.';
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'name is required.';
        }

        $teamspeak = is_array($payload['teamspeak'] ?? null) ? $payload['teamspeak'] : [];
        $profile = MusicbotTeamspeakProfile::tryFrom((string) ($payload['teamspeak_profile'] ?? $teamspeak['profile'] ?? MusicbotTeamspeakProfile::Ts3->value));
        if (!$profile instanceof MusicbotTeamspeakProfile) {
            $errors[] = 'teamspeak_profile must be ts3 or ts6.';
            $profile = MusicbotTeamspeakProfile::Ts3;
        }

        return ['customer' => $customer, 'node' => $node, 'name' => $name, 'cpu_limit' => max(0, (int) ($payload['cpu_limit'] ?? 0)), 'ram_limit' => max(0, (int) ($payload['ram_limit'] ?? 0)), 'disk_limit' => max(0, (int) ($payload['disk_limit'] ?? 0)), 'teamspeak_enabled' => (bool) ($payload['teamspeak_enabled'] ?? ($teamspeak['enabled'] ?? false)), 'teamspeak_config' => ['profile' => $profile->value, 'backend' => 'ts3_client_compatible', 'backend_type' => (string) ($payload['teamspeak_backend_type'] ?? $teamspeak['backend_type'] ?? 'placeholder'), 'backend_path' => (string) ($payload['teamspeak_backend_path'] ?? $teamspeak['backend_path'] ?? ''), 'identity_path' => (string) ($payload['teamspeak_identity_path'] ?? $teamspeak['identity_path'] ?? ''), 'command_prefix' => (string) ($payload['teamspeak_command_prefix'] ?? $teamspeak['command_prefix'] ?? '!'), 'commands_enabled' => (bool) ($payload['teamspeak_commands_enabled'] ?? $teamspeak['commands_enabled'] ?? true), 'events_enabled' => (bool) ($payload['teamspeak_events_enabled'] ?? $teamspeak['events_enabled'] ?? true), 'allowed_server_groups' => (array) ($payload['teamspeak_allowed_server_groups'] ?? $teamspeak['allowed_server_groups'] ?? []), 'dj_server_groups' => (array) ($payload['teamspeak_dj_server_groups'] ?? $teamspeak['dj_server_groups'] ?? []), 'admin_server_groups' => (array) ($payload['teamspeak_admin_server_groups'] ?? $teamspeak['admin_server_groups'] ?? []), 'host' => (string) ($payload['teamspeak_host'] ?? $teamspeak['host'] ?? ''), 'port' => max(1, (int) ($payload['teamspeak_port'] ?? $teamspeak['port'] ?? 9987)), 'nickname' => (string) ($payload['teamspeak_nickname'] ?? $teamspeak['nickname'] ?? ''), 'channel_id' => (string) ($payload['teamspeak_channel_id'] ?? $teamspeak['channel_id'] ?? '')], 'teamspeak_secret_config' => ['server_password' => (string) ($payload['teamspeak_server_password'] ?? $teamspeak['server_password'] ?? ''), 'channel_password' => (string) ($payload['teamspeak_channel_password'] ?? $teamspeak['channel_password'] ?? '')], 'discord_enabled' => (bool) ($payload['discord_enabled'] ?? false), 'errors' => $errors];
    }

    private function buildServiceName(string $name): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $slug = trim($slug, '-') ?: 'musicbot';

        return sprintf('musicbot-%s-%s', substr($slug, 0, 32), bin2hex(random_bytes(3)));
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }

    private function resolveStreamUrlPlaceholder(MusicbotInstance $instance): ?string
    {
        $settings = $this->streamSettingsRepository->findByInstance($instance);
        if ($settings === null) {
            return null;
        }

        return sprintf('/stream/%s', $settings->getPublicSlug());
    }

    /** @return array<string, mixed> */
    private function normalizeCustomerLimitsOverride(?MusicbotCustomerLimits $limits): array
    {
        if (!$limits instanceof MusicbotCustomerLimits) {
            return [];
        }

        return array_filter([
            'max_musicbots' => $limits->getMaxMusicbots(),
            'max_tracks' => $limits->getMaxTracks(),
            'max_storage_mb' => $limits->getMaxStorageMb(),
            'max_playlists' => $limits->getMaxPlaylists(),
            'max_plugins' => $limits->getMaxPlugins(),
            'max_queue_items' => $limits->getMaxQueueItems(),
            'max_connections' => $limits->getMaxConnections(),
            'max_upload_size_mb' => $limits->getMaxUploadSizeMb(),
            'allow_teamspeak' => $limits->getAllowTeamspeak(),
            'allow_discord' => $limits->getAllowDiscord(),
            'allow_teamspeak6_profile' => $limits->getAllowTeamspeak6Profile(),
            'allow_webradio' => $limits->getAllowWebradio(),
            'allow_plugins' => $limits->getAllowPlugins(),
            'allow_workflows' => $limits->getAllowWorkflows(),
            'allow_scheduler' => $limits->getAllowScheduler(),
            'granted_permissions' => $limits->getGrantedPermissions(),
            'updated_at' => $limits->getUpdatedAt()->format(DATE_ATOM),
        ], static fn (mixed $v): bool => $v !== null);
    }
}
