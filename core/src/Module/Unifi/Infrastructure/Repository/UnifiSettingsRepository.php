<?php

declare(strict_types=1);

namespace App\Module\Unifi\Infrastructure\Repository;

use App\Module\Unifi\Domain\Entity\UnifiSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UnifiSettings>
 */
class UnifiSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnifiSettings::class);
    }

    public function getSettings(): UnifiSettings
    {
        $settings = $this->findOneBy([]);
        if ($settings === null) {
            $settings = new UnifiSettings();
            $entityManager = $this->getEntityManager();
            $entityManager->persist($settings);
            $entityManager->flush();

            return $settings;
        }

        if (! $settings instanceof UnifiSettings) {
            throw new \RuntimeException('Unexpected Unifi settings type.');
        }

        return $settings;
    }
}
