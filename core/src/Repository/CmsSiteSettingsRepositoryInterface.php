<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\Site;

interface CmsSiteSettingsRepositoryInterface
{
    public function findOneBySite(Site $site): ?CmsSiteSettings;
}
