<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPluginLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotPluginLog> */
final class MusicbotPluginLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, MusicbotPluginLog::class); }

    /** @return MusicbotPluginLog[] */
    public function findForInstance(MusicbotInstance $instance, int $limit = 50): array
    {
        return $this->findBy(['instance' => $instance], ['createdAt' => 'DESC'], $limit);
    }

    /** @return MusicbotPluginLog[] */
    public function findForCustomer(User $customer, int $limit = 100): array
    {
        return $this->findBy(['customer' => $customer], ['createdAt' => 'DESC'], $limit);
    }
}
