<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotAutoDjSettings;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotAutoDjSettings> */
final class MusicbotAutoDjSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotAutoDjSettings::class);
    }

    public function findByInstance(MusicbotInstance $instance): ?MusicbotAutoDjSettings
    {
        return $this->findOneBy(['instance' => $instance]);
    }

    /** @return MusicbotAutoDjSettings[] */
    public function findEnabledByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer, 'enabled' => true]);
    }

    /** @return MusicbotAutoDjSettings[] */
    public function findAllEnabled(): array
    {
        return $this->findBy(['enabled' => true]);
    }
}
