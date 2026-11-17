<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<CmsSiteSettings>
 */
#[AsAlias(id: CmsSiteSettingsRepositoryInterface::class)]
final class CmsSiteSettingsRepository extends ServiceEntityRepository implements CmsSiteSettingsRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CmsSiteSettings::class);
    }

    #[\Override]
    public function findOneBySite(Site $site): ?CmsSiteSettings
    {
        $settings = $this->findOneBy(['site' => $site]);

        return $settings;
    }
}
