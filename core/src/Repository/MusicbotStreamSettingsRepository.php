<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotStreamSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotStreamSettings> */
final class MusicbotStreamSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotStreamSettings::class);
    }

    public function findByInstance(MusicbotInstance $instance): ?MusicbotStreamSettings
    {
        return $this->findOneBy(['instance' => $instance]);
    }

    public function findBySlug(string $slug): ?MusicbotStreamSettings
    {
        return $this->findOneBy(['publicSlug' => $slug]);
    }

    /** @return MusicbotStreamSettings[] */
    public function findEnabledByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer, 'enabled' => true]);
    }

    /** @return MusicbotStreamSettings[] */
    public function findAllEnabled(): array
    {
        return $this->findBy(['enabled' => true]);
    }
}
