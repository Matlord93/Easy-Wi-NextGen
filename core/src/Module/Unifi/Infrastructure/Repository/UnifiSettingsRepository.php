<?php

declare(strict_types=1);

namespace App\Module\Unifi\Infrastructure\Repository;

use App\Module\Unifi\Domain\Entity\UnifiSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Module\Unifi\Application\UnifiSettingsProviderInterface;

/**
 * @extends ServiceEntityRepository<UnifiSettings>
 */
final class UnifiSettingsRepository extends ServiceEntityRepository implements UnifiSettingsProviderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnifiSettings::class);
    }

    #[\Override]
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
