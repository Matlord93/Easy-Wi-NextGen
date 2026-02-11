<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CmsSiteSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CmsSiteSettings::class);
    }

    public function findOneBySite(Site $site): ?CmsSiteSettings
    {
        /** @var CmsSiteSettings|null $settings */
        $settings = $this->findOneBy(['site' => $site]);

        return $settings;
    }
}
