<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylist;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotPermission;
use App\Module\Musicbot\Domain\Enum\MusicbotRoleChannel;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
use App\Repository\MusicbotPlaylistRepository;
use App\Repository\MusicbotTrackRepository;

final class MusicbotPluginActionService
{
    public function __construct(
        private readonly MusicbotQueueService $queueService,
        private readonly MusicbotPlaylistService $playlistService,
        private readonly MusicbotPlaybackCommandService $playbackCommandService,
        private readonly MusicbotAutoDjService $autoDjService,
        private readonly MusicbotTrackService $trackService,
        private readonly MusicbotQuotaService $quotaService,
        private readonly MusicbotPermissionService $permissionService,
        private readonly MusicbotTrackRepository $trackRepository,
        private readonly MusicbotPlaylistRepository $playlistRepository,
        private readonly AgentJobDispatcher $jobDispatcher,
    ) {}

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function execute(User $customer, MusicbotInstance $instance, string $action, array $payload): array
    {
        $this->assertOwnership($customer, $instance);

        $requiredPermission = match ($action) {
            'play_track', 'set_volume', 'send_chat_message' => MusicbotPermission::PlaybackControl,
            'queue_track', 'clear_queue'                    => MusicbotPermission::QueueManage,
            'play_playlist'                                 => MusicbotPermission::PlaylistsManage,
            'play_radio'                                    => MusicbotPermission::WebradioManage,
            'play_youtube'                                  => MusicbotPermission::YoutubeManage,
            'reconnect'                                     => MusicbotPermission::Restart,
            'trigger_autodj'                                => MusicbotPermission::AutoDjManage,
            default                                         => throw new \InvalidArgumentException('Unsupported plugin action.'),
        };

        $this->permissionService->assertActionAllowed($customer, $instance, $requiredPermission, MusicbotRoleChannel::Api);

        return match ($action) {
            'play_track'        => $this->playTrack($customer, $instance, (int) ($payload['track_id'] ?? 0)),
            'queue_track'       => $this->queueTrack($customer, $instance, (int) ($payload['track_id'] ?? 0)),
            'play_playlist'     => $this->playPlaylist($customer, $instance, (int) ($payload['playlist_id'] ?? 0), (bool) ($payload['shuffle'] ?? false)),
            'play_radio'        => $this->playRadio($customer, $instance, (string) ($payload['radio_url'] ?? '')),
            'play_youtube'      => $this->playYoutube($customer, $instance, (string) ($payload['youtube_url'] ?? '')),
            'clear_queue'       => $this->clearQueue($customer, $instance),
            'set_volume'        => $this->setVolume($customer, $instance, (int) ($payload['volume'] ?? 50)),
            'send_chat_message' => $this->sendChatMessage($customer, $instance, (string) ($payload['message'] ?? '')),
            'reconnect'         => $this->reconnect($customer, $instance),
            'trigger_autodj'    => ['tracks_added' => $this->autoDjService->trigger($customer, $instance)],
            default             => throw new \InvalidArgumentException('Unsupported plugin action.'),
        };
    }

    /** @return array<string, mixed> */
    private function playTrack(User $customer, MusicbotInstance $instance, int $trackId): array
    {
        $item = $this->queueTrack($customer, $instance, $trackId);
        $job = $this->playbackCommandService->dispatchPlaybackAction($customer, $instance, 'play');
        return $item + ['playback_job_id' => $job->getId()];
    }

    /** @return array<string, mixed> */
    private function queueTrack(User $customer, MusicbotInstance $instance, int $trackId): array
    {
        $track = $this->trackRepository->findOneForCustomer($trackId, $customer);
        if (!$track instanceof MusicbotTrack) { throw new \InvalidArgumentException('Plugin track does not belong to this customer.'); }
        $queueItem = $this->queueService->addTrackToQueue($customer, $instance, $track, $customer);
        return ['queue_item_id' => $queueItem->getId(), 'track_id' => $track->getId()];
    }

    /** @return array<string, mixed> */
    private function playPlaylist(User $customer, MusicbotInstance $instance, int $playlistId, bool $shuffle): array
    {
        $playlist = $this->playlistRepository->findOneForCustomer($playlistId, $customer);
        if (!$playlist instanceof MusicbotPlaylist) { throw new \InvalidArgumentException('Plugin playlist does not belong to this customer.'); }
        $items = $this->playlistService->loadPlaylistToQueueMode($customer, $playlist, $instance, $shuffle ? 'shuffle_play' : 'clear_play');
        $job = $items !== [] ? $this->playbackCommandService->dispatchPlaybackAction($customer, $instance, 'play') : null;
        return ['queued_tracks' => count($items), 'playback_job_id' => $job?->getId()];
    }

    /** @return array<string, mixed> */
    private function playRadio(User $customer, MusicbotInstance $instance, string $url): array
    {
        $this->quotaService->assertWebradioAllowed($customer);
        $track = $this->trackService->addWebradioTrack($customer, 'Plugin Radio', $url);
        $queueItem = $this->queueService->addTrackToQueue($customer, $instance, $track, $customer);
        $job = $this->playbackCommandService->dispatchPlaybackAction($customer, $instance, 'play');
        return ['queue_item_id' => $queueItem->getId(), 'track_id' => $track->getId(), 'playback_job_id' => $job->getId()];
    }

    /** @return array<string, mixed> */
    private function playYoutube(User $customer, MusicbotInstance $instance, string $url): array
    {
        $this->quotaService->assertYoutubeAllowed($customer);
        $track = $this->trackService->addYoutubeTrack($customer, $url);
        $queueItem = $this->queueService->addTrackToQueue($customer, $instance, $track, $customer);
        $job = $this->playbackCommandService->dispatchPlaybackAction($customer, $instance, 'play');
        return ['queue_item_id' => $queueItem->getId(), 'track_id' => $track->getId(), 'source_type' => MusicbotTrackSourceType::Youtube->value, 'playback_job_id' => $job->getId()];
    }

    /** @return array<string, mixed> */
    private function clearQueue(User $customer, MusicbotInstance $instance): array { $this->queueService->clearQueue($customer, $instance); return ['cleared' => true]; }

    /** @return array<string, mixed> */
    private function setVolume(User $customer, MusicbotInstance $instance, int $volume): array { $this->playbackCommandService->storeVolume($customer, $instance, $volume); return ['volume' => $volume]; }

    /** @return array<string, mixed> */
    private function sendChatMessage(User $customer, MusicbotInstance $instance, string $message): array
    {
        $job = $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.chat.message', ['instance_id' => (string) $instance->getId(), 'customer_id' => (string) $customer->getId(), 'service_name' => $instance->getServiceName(), 'install_path' => $instance->getInstallPath(), 'message' => mb_substr($message, 0, 500)]);
        return ['job_id' => $job->getId()];
    }

    /** @return array<string, mixed> */
    private function reconnect(User $customer, MusicbotInstance $instance): array
    {
        $job = $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.playback.action', ['instance_id' => (string) $instance->getId(), 'customer_id' => (string) $customer->getId(), 'service_name' => $instance->getServiceName(), 'install_path' => $instance->getInstallPath(), 'action' => 'reload_config', 'reconnect_if_required' => true]);
        return ['job_id' => $job->getId()];
    }

    private function assertOwnership(User $customer, MusicbotInstance $instance): void
    {
        if ($instance->getCustomer()->getId() !== $customer->getId()) { throw new \RuntimeException('Musicbot instance does not belong to this customer.'); }
    }
}
