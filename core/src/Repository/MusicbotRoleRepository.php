<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotRole> */
final class MusicbotRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotRole::class);
    }

    public function findById(int $id): ?MusicbotRole
    {
        return $this->find($id);
    }

    /** @return MusicbotRole[] */
    public function findByInstance(MusicbotInstance $instance): array
    {
        return $this->findBy(['instance' => $instance], ['position' => 'ASC', 'name' => 'ASC']);
    }

    /** @return MusicbotRole[] */
    public function findDefaultsForInstance(MusicbotInstance $instance): array
    {
        return $this->findBy(['instance' => $instance, 'isDefault' => true]);
    }

    public function findOneForInstance(int $id, MusicbotInstance $instance): ?MusicbotRole
    {
        return $this->findOneBy(['id' => $id, 'instance' => $instance]);
    }
}
