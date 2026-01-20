<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Repository;

use App\Module\Gameserver\Domain\Entity\GameProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class GameProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameProfile::class);
    }

    public function findOneByGameKey(string $gameKey): ?GameProfile
    {
        return $this->findOneBy(['gameKey' => $gameKey]);
    }
}
