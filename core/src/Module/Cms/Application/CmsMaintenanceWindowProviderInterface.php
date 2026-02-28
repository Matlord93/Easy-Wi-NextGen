<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Module\Core\Domain\Entity\MaintenanceWindow;

interface CmsMaintenanceWindowProviderInterface
{
    /** @return MaintenanceWindow[] */
    public function findCurrentPublicBySite(int $siteId, \DateTimeImmutable $now): array;
}
