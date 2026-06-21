<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylist;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylistItem;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotPlaylistVisibility;
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

    public function createPlaylist(User $customer, string $name, ?MusicbotInstance $instance = null, MusicbotPlaylistVisibility $visibility = MusicbotPlaylistVisibility::Private): MusicbotPlaylist
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
        $this->entityManager->persist($playlist);
        $this->entityManager->flush();

        return $playlist;
    }

    public function updatePlaylist(User $customer, MusicbotPlaylist $playlist, string $name, MusicbotPlaylistVisibility $visibility): MusicbotPlaylist
    {
        $this->assertCustomerOwnsPlaylist($customer, $playlist);
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Playlist name is required.');
        }
        $playlist->setName($name);
        $playlist->setVisibility($visibility);
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
