<?php

declare(strict_types=1);

namespace App\Module\Unifi\Application;

use App\Module\Unifi\Domain\Entity\UnifiSettings;

interface UnifiSettingsProviderInterface
{
    public function getSettings(): UnifiSettings;
}
