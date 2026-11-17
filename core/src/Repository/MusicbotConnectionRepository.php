<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotConnection> */
class MusicbotConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotConnection::class);
    }

}
