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
        if ($settings instanceof UnifiSettings) {
            return $settings;
        }

        $settings = new UnifiSettings();
        $this->_em->persist($settings);
        $this->_em->flush();

        return $settings;
    }
}
