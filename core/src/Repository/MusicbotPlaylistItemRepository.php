<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylist;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylistItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotPlaylistItem> */
class MusicbotPlaylistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotPlaylistItem::class);
    }

    /** @return MusicbotPlaylistItem[] */
    public function findByCustomer(User $customer): array
    {
        return $this->createQueryBuilder('playlistItem')
            ->innerJoin('playlistItem.playlist', 'playlist')
            ->andWhere('playlist.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('playlistItem.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return MusicbotPlaylistItem[] */
    public function findByPlaylistOrdered(MusicbotPlaylist $playlist): array
    {
        return $this->findBy(['playlist' => $playlist], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneForCustomer(int $id, User $customer): ?MusicbotPlaylistItem
    {
        return $this->createQueryBuilder('playlistItem')
            ->innerJoin('playlistItem.playlist', 'playlist')
            ->andWhere('playlistItem.id = :id')
            ->andWhere('playlist.customer = :customer')
            ->setParameter('id', $id)
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
