<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
use App\Repository\MusicbotQueueItemRepositoryInterface;
use App\Module\Musicbot\Domain\Enum\MusicbotRepeatMode;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotPlaybackCommandService
{
    private const PLAYBACK_ACTIONS = ['play', 'pause', 'resume', 'stop', 'skip', 'volume', 'seek', 'shuffle', 'repeat'];

    public function __construct(
        private readonly AgentJobDispatcherInterface $jobDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotQueueItemRepositoryInterface $queueItemRepository,
        private readonly MusicbotTrackPathResolver $trackPathResolver,
    ) {
    }

    /** @param array<string, mixed> $extraPayload */
    public function dispatchPlaybackAction(User $customer, MusicbotInstance $instance, string $action, array $extraPayload = []): AgentJob
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        $normalizedAction = strtolower(trim($action));
        if (!in_array($normalizedAction, self::PLAYBACK_ACTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported musicbot playback action.');
        }
        if ($normalizedAction === 'play') {
            $extraPayload = array_merge($this->resolvePlayPayload($instance), $extraPayload);
        }

        return $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.playback.action', array_merge([
            'instance_id' => (string) $instance->getId(),
            'action' => $normalizedAction,
            'service_name' => $instance->getServiceName(),
            'install_path' => $instance->getInstallPath(),
        ], $extraPayload));
    }

    public function prepareSkip(User $customer, MusicbotInstance $instance): AgentJob
    {
        return $this->dispatchPlaybackAction($customer, $instance, 'skip');
    }

    public function storeRepeatMode(User $customer, MusicbotInstance $instance, MusicbotRepeatMode $repeatMode): void
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        $payload = $instance->getRuntimePayload() ?? [];
        $payload['playback'] = array_merge($payload['playback'] ?? [], ['repeat_mode' => $repeatMode->value]);
        $instance->setRuntimePayload($payload);
        $this->entityManager->flush();
    }

    public function storeShuffle(User $customer, MusicbotInstance $instance, bool $shuffle): void
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        $payload = $instance->getRuntimePayload() ?? [];
        $payload['playback'] = array_merge($payload['playback'] ?? [], ['shuffle' => $shuffle]);
        $instance->setRuntimePayload($payload);
        $this->entityManager->flush();
    }

    public function storeVolume(User $customer, MusicbotInstance $instance, int $volume): void
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        if ($volume < 0 || $volume > 100) {
            throw new \InvalidArgumentException('Die Lautstärke muss eine Zahl zwischen 0 und 100 sein.');
        }
        $payload = $instance->getRuntimePayload() ?? [];
        $payload['playback'] = array_merge($payload['playback'] ?? [], ['volume' => $volume, 'desired_volume' => $volume]);
        $instance->setRuntimePayload($payload);
        $config = $instance->getInstanceConfig();
        $config['live_volume'] = $volume;
        $config['live_volume_updated_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $instance->setInstanceConfig($config);
        $this->entityManager->flush();
    }

    private function assertCustomerOwnsInstance(User $customer, MusicbotInstance $instance): void
    {
        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Musicbot instance does not belong to the current customer.');
        }
    }

    /** @return array<string, mixed> */
    private function resolvePlayPayload(MusicbotInstance $instance): array
    {
        $queue = $this->queueItemRepository->findQueueForInstanceOrdered($instance);
        $item = $queue[0] ?? null;
        if ($item === null) {
            throw new \RuntimeException('Kein Track oder Webradio ausgewählt.');
        }
        $track = $item->getTrack();
        if ($track === null) {
            throw new \RuntimeException(MusicbotTrackPathResolver::MISSING_FILE_MESSAGE);
        }
        if ($track->getSourceType() === MusicbotTrackSourceType::Webradio) {
            $url = (string) ($track->getMetadata()['stream_url'] ?? '');
            if ($url === '') {
                throw new \RuntimeException('Kein Track oder Webradio ausgewählt.');
            }

            return ['source_type' => 'radio', 'source' => ['type' => 'radio', 'uri' => $url], 'queue_item_id' => (string) $item->getId(), 'track_id' => (string) $track->getId(), 'radio_url' => $url, 'url' => $url];
        }
        if ($track->getSourceType() === MusicbotTrackSourceType::Youtube) {
            $url = (string) ($track->getMetadata()['youtube_url'] ?? '');
            if ($url === '') {
                throw new \RuntimeException('Kein YouTube-Link ausgewählt.');
            }

            return ['source_type' => 'youtube', 'source' => ['type' => 'youtube', 'youtube_url' => $url], 'queue_item_id' => (string) $item->getId(), 'track_id' => (string) $track->getId(), 'youtube_url' => $url];
        }
        if ($track->getSourceType() !== MusicbotTrackSourceType::Upload) {
            return ['source_type' => $track->getSourceType()->value, 'queue_item_id' => (string) $item->getId(), 'track_id' => (string) $track->getId()];
        }
        $path = $this->trackPathResolver->resolveTrackFile($track, $instance);
        if ($path === null) {
            throw new \RuntimeException(MusicbotTrackPathResolver::MISSING_FILE_MESSAGE);
        }

        return ['source_type' => 'upload', 'queue_item_id' => (string) $item->getId(), 'track_id' => (string) $track->getId(), 'file_path' => $path, 'path' => $path];
    }
}
