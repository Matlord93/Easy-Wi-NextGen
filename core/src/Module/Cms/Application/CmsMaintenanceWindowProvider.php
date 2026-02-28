<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Repository\MaintenanceWindowRepository;

final class CmsMaintenanceWindowProvider implements CmsMaintenanceWindowProviderInterface
{
    public function __construct(private readonly MaintenanceWindowRepository $repository)
    {
    }

    public function findCurrentPublicBySite(int $siteId, \DateTimeImmutable $now): array
    {
        return $this->repository->findCurrentPublicBySite($siteId, $now);
    }
}
