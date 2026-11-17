<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @extends ServiceEntityRepository<MusicbotTrack> */
#[AsAlias(id: MusicbotTrackRepositoryInterface::class)]
#[AsAlias(id: MusicbotTrackQueryRepositoryInterface::class)]
final class MusicbotTrackRepository extends ServiceEntityRepository implements MusicbotTrackQueryRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotTrack::class);
    }

    /** @return MusicbotTrack[] */
    #[\Override]
    public function findByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer], ['createdAt' => 'DESC']);
    }

    #[\Override]
    public function findOneForCustomer(int $id, User $customer): ?MusicbotTrack
    {
        return $this->findOneBy(['id' => $id, 'customer' => $customer]);
    }
}
