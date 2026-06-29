<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylist;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylistItem;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotPlaylistVisibility;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
use App\Repository\MusicbotPlaylistItemRepository;
use App\Repository\MusicbotPlaylistRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotPlaylistService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotPlaylistRepository $playlistRepository,
        private readonly MusicbotPlaylistItemRepository $playlistItemRepository,
        private readonly MusicbotQueueService $queueService,
        private readonly MusicbotQuotaService $quotaService,
    ) {
    }

    /** @return MusicbotPlaylist[] */
    public function playlistsForCustomer(User $customer): array
    {
        return $this->playlistRepository->findByCustomer($customer);
    }

    public function createPlaylist(User $customer, string $name, ?MusicbotInstance $instance = null, MusicbotPlaylistVisibility $visibility = MusicbotPlaylistVisibility::Private, ?string $description = null): MusicbotPlaylist
    {
        if ($instance instanceof MusicbotInstance) {
            $this->assertCustomerOwnsInstance($customer, $instance);
        }
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Playlist name is required.');
        }
        $this->quotaService->assertCanCreatePlaylist($customer);

        $playlist = new MusicbotPlaylist($customer, $name, $instance);
        $playlist->setVisibility($visibility);
        $playlist->setDescription($description);
        $this->entityManager->persist($playlist);
        $this->entityManager->flush();

        return $playlist;
    }

    public function updatePlaylist(User $customer, MusicbotPlaylist $playlist, string $name, MusicbotPlaylistVisibility $visibility, ?string $description = null): MusicbotPlaylist
    {
        $this->assertCustomerOwnsPlaylist($customer, $playlist);
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Playlist name is required.');
        }
        $playlist->setName($name);
        $playlist->setVisibility($visibility);
        $playlist->setDescription($description);
        $this->entityManager->flush();

        return $playlist;
    }

    public function deletePlaylist(User $customer, MusicbotPlaylist $playlist): void
    {
        $this->assertCustomerOwnsPlaylist($customer, $playlist);
        $this->entityManager->remove($playlist);
        $this->entityManager->flush();
    }

    public function addTrack(User $customer, MusicbotPlaylist $playlist, MusicbotTrack $track): MusicbotPlaylistItem
    {
        $this->assertCustomerOwnsPlaylist($customer, $playlist);
        $this->assertCustomerOwnsTrack($customer, $track);
        $this->assertTrackAllowedByPlan($customer, $track);
        $this->assertTrackMatchesPlaylistInstance($playlist, $track);
        $this->quotaService->assertCanAddPlaylistItem($customer, $playlist);
        $position = count($this->playlistItemRepository->findByPlaylistOrdered($playlist));
        $item = new MusicbotPlaylistItem($playlist, $track, $position);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item;
    }

    public function removeItem(User $customer, MusicbotPlaylistItem $item): void
    {
        $playlist = $item->getPlaylist();
        $this->assertCustomerOwnsPlaylist($customer, $playlist);
        $this->entityManager->remove($item);
        $this->entityManager->flush();
        $this->normalizePositions($playlist);
    }

    /** @return \App\Module\Musicbot\Domain\Entity\MusicbotQueueItem[] */
    public function loadPlaylistToQueue(User $customer, MusicbotPlaylist $playlist, MusicbotInstance $instance): array
    {
        $this->assertCustomerOwnsPlaylist($customer, $playlist);
        $this->assertCustomerOwnsInstance($customer, $instance);
        $queueItems = [];
        foreach ($this->playlistItemRepository->findByPlaylistOrdered($playlist) as $playlistItem) {
            $queueItems[] = $this->queueService->addTrackToQueue($customer, $instance, $playlistItem->getTrack(), $customer);
        }

        return $queueItems;
    }


    /** @param list<int> $playlistItemIds */
    public function reorderItems(User $customer, MusicbotPlaylist $playlist, array $playlistItemIds): void
    {
        $this->assertCustomerOwnsPlaylist($customer, $playlist);
        $itemsById = [];
        foreach ($this->playlistItemRepository->findByPlaylistOrdered($playlist) as $item) {
            if ($item->getId() !== null) {
                $itemsById[$item->getId()] = $item;
            }
        }
        $position = 0;
        foreach ($playlistItemIds as $itemId) {
            if (!isset($itemsById[$itemId])) {
                throw new \InvalidArgumentException('Playlist item does not belong to this playlist.');
            }
            $itemsById[$itemId]->setPosition($position++);
            unset($itemsById[$itemId]);
        }
        foreach ($itemsById as $item) {
            $item->setPosition($position++);
        }
        $this->entityManager->flush();
    }

    /** @return \App\Module\Musicbot\Domain\Entity\MusicbotQueueItem[] */
    public function loadPlaylistToQueueMode(User $customer, MusicbotPlaylist $playlist, MusicbotInstance $instance, string $mode = 'add'): array
    {
        $this->assertCustomerOwnsPlaylist($customer, $playlist);
        $this->assertCustomerOwnsInstance($customer, $instance);
        $items = $this->playlistItemRepository->findByPlaylistOrdered($playlist);
        if ($mode === 'shuffle_add' || $mode === 'shuffle_play') {
            shuffle($items);
        }
        if (in_array($mode, ['play_now', 'clear_play', 'shuffle_play'], true)) {
            $this->queueService->clearQueue($customer, $instance);
        }
        $queueItems = [];
        foreach ($items as $playlistItem) {
            $queueItems[] = $this->queueService->addTrackToQueue($customer, $instance, $playlistItem->getTrack(), $customer);
        }

        return $queueItems;
    }

    public function findPlaylistForCustomer(int $playlistId, User $customer): ?MusicbotPlaylist
    {
        return $this->playlistRepository->findOneForCustomer($playlistId, $customer);
    }

    public function findPlaylistItemForCustomer(int $itemId, User $customer): ?MusicbotPlaylistItem
    {
        return $this->playlistItemRepository->findOneForCustomer($itemId, $customer);
    }

    /** @return MusicbotPlaylistItem[] */
    public function itemsForPlaylist(User $customer, MusicbotPlaylist $playlist): array
    {
        $this->assertCustomerOwnsPlaylist($customer, $playlist);

        return $this->playlistItemRepository->findByPlaylistOrdered($playlist);
    }

    private function normalizePositions(MusicbotPlaylist $playlist): void
    {
        $position = 0;
        foreach ($this->playlistItemRepository->findByPlaylistOrdered($playlist) as $item) {
            $item->setPosition($position++);
        }
        $this->entityManager->flush();
    }

    private function assertTrackAllowedByPlan(User $customer, MusicbotTrack $track): void
    {
        if ($track->getSourceType() === MusicbotTrackSourceType::Webradio) {
            $this->quotaService->assertWebradioAllowed($customer);
        }
        if ($track->getSourceType() === MusicbotTrackSourceType::Youtube) {
            $this->quotaService->assertYoutubeAllowed($customer);
        }
    }

    private function assertTrackMatchesPlaylistInstance(MusicbotPlaylist $playlist, MusicbotTrack $track): void
    {
        if ($playlist->getInstance() instanceof MusicbotInstance && $track->getInstance() instanceof MusicbotInstance && $playlist->getInstance()->getId() !== $track->getInstance()->getId()) {
            throw new \InvalidArgumentException('Track belongs to another musicbot instance.');
        }
    }

    private function assertCustomerOwnsPlaylist(User $customer, MusicbotPlaylist $playlist): void
    {
        if ($playlist->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Playlist does not belong to the current customer.');
        }
    }

    private function assertCustomerOwnsTrack(User $customer, MusicbotTrack $track): void
    {
        if ($track->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Track does not belong to the current customer.');
        }
    }

    private function assertCustomerOwnsInstance(User $customer, MusicbotInstance $instance): void
    {
        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Musicbot instance does not belong to the current customer.');
        }
    }
}
