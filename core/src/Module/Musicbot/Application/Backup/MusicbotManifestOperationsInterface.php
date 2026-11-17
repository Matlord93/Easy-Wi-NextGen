<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Module\Core\Application\Backup\Adapter\RestoreReport;

interface MusicbotManifestOperationsInterface
{
    public function exportManifest(string $resourceId): string;

    public function restoreManifest(string $resourceId, string $json, bool $dryRun): RestoreReport;
}
