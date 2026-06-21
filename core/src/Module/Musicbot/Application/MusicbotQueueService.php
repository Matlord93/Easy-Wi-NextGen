<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotQueueItem;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
use App\Repository\MusicbotQueueItemRepository;
use App\Repository\MusicbotTrackRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotQueueService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotQueueItemRepository $queueItemRepository,
        private readonly MusicbotTrackRepository $trackRepository,
        private readonly MusicbotQuotaService $quotaService,
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

        return $queueItem;
    }

    public function removeQueueItem(User $customer, MusicbotQueueItem $queueItem): void
    {
        $this->assertCustomerOwnsInstance($customer, $queueItem->getInstance());
        $instance = $queueItem->getInstance();
        $this->entityManager->remove($queueItem);
        $this->entityManager->flush();
        $this->normalizePositions($instance);
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
    }

    public function clearQueue(User $customer, MusicbotInstance $instance): void
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        foreach ($this->queueItemRepository->findQueueForInstanceOrdered($instance) as $queueItem) {
            $this->entityManager->remove($queueItem);
        }
        $this->entityManager->flush();
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
