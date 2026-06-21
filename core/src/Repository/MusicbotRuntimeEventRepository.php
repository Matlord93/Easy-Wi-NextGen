<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotRuntimeEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotRuntimeEvent> */
final class MusicbotRuntimeEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotRuntimeEvent::class);
    }

    /** @return MusicbotRuntimeEvent[] */
    public function findLatestForInstance(MusicbotInstance $instance, int $limit = 50): array
    {
        return $this->findBy(['musicbotInstance' => $instance], ['createdAt' => 'DESC'], $limit);
    }

    /** @return MusicbotRuntimeEvent[] */
    public function findByCustomer(User $customer, int $limit = 100): array
    {
        return $this->findBy(['customer' => $customer], ['createdAt' => 'DESC'], $limit);
    }

    /** @return MusicbotRuntimeEvent[] */
    public function findByType(string $type, int $limit = 100): array
    {
        return $this->findBy(['type' => $type], ['createdAt' => 'DESC'], $limit);
    }

    /** @return MusicbotRuntimeEvent[] */
    public function findErrorsForInstance(MusicbotInstance $instance, int $limit = 50): array
    {
        return $this->findBy(['musicbotInstance' => $instance, 'level' => 'error'], ['createdAt' => 'DESC'], $limit);
    }
}
