<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotTrack> */
final class MusicbotTrackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotTrack::class);
    }

    /** @return MusicbotTrack[] */
    public function findByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer], ['createdAt' => 'DESC']);
    }

    public function findOneForCustomer(int $id, User $customer): ?MusicbotTrack
    {
        return $this->findOneBy(['id' => $id, 'customer' => $customer]);
    }
}
