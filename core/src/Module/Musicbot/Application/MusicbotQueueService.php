<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotQueueItem;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
use App\Repository\MusicbotQueueItemRepositoryInterface;
use App\Repository\MusicbotTrackRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotQueueService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotQueueItemRepositoryInterface $queueItemRepository,
        private readonly MusicbotTrackRepositoryInterface $trackRepository,
        private readonly MusicbotQuotaServiceInterface $quotaService,
        private readonly AgentJobDispatcherInterface $jobDispatcher,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function createTrackForCustomer(
        User $customer,
        string $title,
        MusicbotTrackSourceType $sourceType,
        string $mimeType,
        string $sha256,
        int $durationSeconds = 0,
        ?MusicbotInstance $instance = null,
        ?string $artist = null,
        ?string $filePath = null,
        array $metadata = [],
    ): MusicbotTrack {
        if ($instance instanceof MusicbotInstance) {
            $this->assertCustomerOwnsInstance($customer, $instance);
        }

        $track = new MusicbotTrack($customer, trim($title), $sourceType, trim($mimeType), trim($sha256), $durationSeconds, $metadata);
        $track->setInstance($instance);
        $track->setArtist($artist !== null ? trim($artist) : null);
        $track->setFilePath($filePath !== null ? trim($filePath) : null);

        $this->entityManager->persist($track);
        $this->entityManager->flush();

        return $track;
    }

    public function addTrackToQueue(User $customer, MusicbotInstance $instance, MusicbotTrack $track, ?User $requestedBy = null): MusicbotQueueItem
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        $this->assertCustomerOwnsTrack($customer, $track);
        if ($track->getInstance() instanceof MusicbotInstance && $track->getInstance()->getId() !== $instance->getId()) {
            throw new \InvalidArgumentException('Track belongs to another musicbot instance.');
        }
        $this->quotaService->assertCanAddToQueue($customer, $instance);

        $position = count($this->queueItemRepository->findQueueForInstanceOrdered($instance));
        $queueItem = new MusicbotQueueItem($instance, $track, $position, $requestedBy ?? $customer);
        $this->entityManager->persist($queueItem);
        $this->entityManager->flush();

        $this->dispatchQueueSync($instance);

        return $queueItem;
    }

    /**
     * Add a track at the front of the queue (position 0) and shift all existing items down.
     * Used by "Play Now" to immediately start a specific track.
     */
    public function prependTrackToQueue(User $customer, MusicbotInstance $instance, MusicbotTrack $track, ?User $requestedBy = null): MusicbotQueueItem
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        $this->assertCustomerOwnsTrack($customer, $track);
        if ($track->getInstance() instanceof MusicbotInstance && $track->getInstance()->getId() !== $instance->getId()) {
            throw new \InvalidArgumentException('Track belongs to another musicbot instance.');
        }
        $this->quotaService->assertCanAddToQueue($customer, $instance);

        // Shift all existing items one position down.
        foreach ($this->queueItemRepository->findQueueForInstanceOrdered($instance) as $existing) {
            $existing->setPosition($existing->getPosition() + 1);
        }

        $queueItem = new MusicbotQueueItem($instance, $track, 0, $requestedBy ?? $customer);
        $this->entityManager->persist($queueItem);
        $this->entityManager->flush();

        $this->dispatchQueueSync($instance);

        return $queueItem;
    }

    public function removeQueueItem(User $customer, MusicbotQueueItem $queueItem): void
    {
        $this->assertCustomerOwnsInstance($customer, $queueItem->getInstance());
        $instance = $queueItem->getInstance();
        $this->entityManager->remove($queueItem);
        $this->entityManager->flush();
        $this->normalizePositions($instance);
        $this->dispatchQueueSync($instance);
    }

    public function removeTrack(User $customer, MusicbotTrack $track): void
    {
        $this->assertCustomerOwnsTrack($customer, $track);
        $this->entityManager->remove($track);
        $this->entityManager->flush();
    }

    /** @param list<int> $queueItemIds */
    public function sortQueue(User $customer, MusicbotInstance $instance, array $queueItemIds): void
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        $itemsById = [];
        foreach ($this->queueItemRepository->findQueueForInstanceOrdered($instance) as $item) {
            $id = $item->getId();
            if ($id !== null) {
                $itemsById[$id] = $item;
            }
        }

        $position = 0;
        foreach ($queueItemIds as $queueItemId) {
            if (!isset($itemsById[$queueItemId])) {
                throw new \InvalidArgumentException('Queue item does not belong to this musicbot instance.');
            }
            $itemsById[$queueItemId]->setPosition($position++);
            unset($itemsById[$queueItemId]);
        }
        foreach ($itemsById as $item) {
            $item->setPosition($position++);
        }
        $this->entityManager->flush();
        $this->dispatchQueueSync($instance);
    }

    public function clearQueue(User $customer, MusicbotInstance $instance): void
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        foreach ($this->queueItemRepository->findQueueForInstanceOrdered($instance) as $queueItem) {
            $this->entityManager->remove($queueItem);
        }
        $this->entityManager->flush();
        $this->dispatchQueueSync($instance);
    }

    /** @return MusicbotQueueItem[] */
    public function queueForCustomerInstance(User $customer, MusicbotInstance $instance): array
    {
        $this->assertCustomerOwnsInstance($customer, $instance);

        return $this->queueItemRepository->findQueueForInstanceOrdered($instance);
    }

    public function findTrackForCustomer(int $trackId, User $customer): ?MusicbotTrack
    {
        return $this->trackRepository->findOneForCustomer($trackId, $customer);
    }

    private function normalizePositions(MusicbotInstance $instance): void
    {
        $position = 0;
        foreach ($this->queueItemRepository->findQueueForInstanceOrdered($instance) as $item) {
            $item->setPosition($position++);
        }
        $this->entityManager->flush();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildQueueSnapshot(MusicbotInstance $instance): array
    {
        $items = $this->queueItemRepository->findQueueForInstanceOrdered($instance);
        $serialized = [];
        foreach ($items as $item) {
            $track = $item->getTrack();
            $sourceEntry = $this->buildSourceEntry($track, $instance->getInstallPath());
            if ($sourceEntry === null) {
                continue;
            }
            $serialized[] = [
                'queue_item_id' => (string) $item->getId(),
                'track_id' => (string) $track->getId(),
                'title' => $track->getTitle(),
                'artist' => $track->getArtist() ?? '',
                'duration_seconds' => $track->getDurationSeconds(),
                'source' => $sourceEntry,
                'metadata' => $track->getMetadata(),
            ];
        }

        return [
            'instance_id' => (string) $instance->getId(),
            'items' => $serialized,
            'revision' => time(),
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
        ];
    }

    /**
     * Build the `source` sub-object for the queue snapshot for the given track.
     * Returns null when the track has no usable URI and should be skipped.
     *
     * @return array<string, string>|null
     */
    private function buildSourceEntry(MusicbotTrack $track, string $installPath): ?array
    {
        return match ($track->getSourceType()) {
            MusicbotTrackSourceType::Upload => $this->buildUploadSource($track, $installPath),
            MusicbotTrackSourceType::Webradio => $this->buildWebradioSource($track),
            MusicbotTrackSourceType::Youtube => $this->buildYoutubeSource($track),
            default => null,
        };
    }

    /** @return array<string, string>|null */
    private function buildUploadSource(MusicbotTrack $track, string $installPath): ?array
    {
        $uri = $this->runtimeSafeFilePath($track, $installPath);
        if ($uri === '') {
            return null;
        }

        return ['type' => MusicbotTrackSourceType::Upload->value, 'uri' => $uri, 'mime_type' => $track->getMimeType()];
    }

    /** @return array<string, string>|null */
    private function buildWebradioSource(MusicbotTrack $track): ?array
    {
        $streamUrl = (string) ($track->getMetadata()['stream_url'] ?? '');
        if ($streamUrl === '' || !str_starts_with($streamUrl, 'http')) {
            return null;
        }

        return ['type' => MusicbotTrackSourceType::Webradio->value, 'uri' => $streamUrl, 'mime_type' => 'audio/mpeg'];
    }

    /** @return array<string, string>|null */
    private function buildYoutubeSource(MusicbotTrack $track): ?array
    {
        $metadata = $track->getMetadata();
        // Prefer a previously resolved direct audio URL (cached by the resolver service).
        $resolvedUrl = (string) ($metadata['resolved_url'] ?? '');
        if ($resolvedUrl !== '' && str_starts_with($resolvedUrl, 'http')) {
            return ['type' => MusicbotTrackSourceType::Upload->value, 'uri' => $resolvedUrl, 'mime_type' => 'audio/mpeg'];
        }

        $youtubeUrl = (string) ($metadata['youtube_url'] ?? '');
        if ($youtubeUrl === '') {
            return null;
        }

        // Pass the YouTube URL to the runtime. The agent resolves it via yt-dlp on its side.
        return ['type' => MusicbotTrackSourceType::Youtube->value, 'uri' => $youtubeUrl, 'mime_type' => 'audio/mpeg'];
    }

    private function runtimeSafeFilePath(MusicbotTrack $track, string $installPath): string
    {
        $filePath = $track->getFilePath();
        if ($filePath === null || trim($filePath) === '') {
            return '';
        }
        $filePath = trim($filePath);

        // Strip absolute data-dir prefix so the runtime receives a relative path
        $dataDir = rtrim($installPath, '/') . '/data/';
        if (str_starts_with($filePath, $dataDir)) {
            $filePath = substr($filePath, strlen($dataDir));
        }

        // Reject absolute paths and traversal attempts
        if (str_starts_with($filePath, '/') || str_contains($filePath, '..')) {
            return '';
        }

        return $filePath;
    }

    private function dispatchQueueSync(MusicbotInstance $instance): void
    {
        $snapshot = $this->buildQueueSnapshot($instance);
        $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.queue.sync', [
            'instance_id' => (string) $instance->getId(),
            'service_name' => $instance->getServiceName(),
            'install_path' => $instance->getInstallPath(),
            'queue_length' => count($snapshot['items']),
            'queue' => $snapshot,
        ]);
    }

    private function assertCustomerOwnsInstance(User $customer, MusicbotInstance $instance): void
    {
        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Musicbot instance does not belong to the current customer.');
        }
    }

    private function assertCustomerOwnsTrack(User $customer, MusicbotTrack $track): void
    {
        if ($track->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Track does not belong to the current customer.');
        }
    }
}
