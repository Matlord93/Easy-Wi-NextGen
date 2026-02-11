<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

interface MigrationStatusProviderInterface
{
    /**
     * @return array{pending: int|null, executedUnavailable: int|null, error: string|null}
     */
    public function getMigrationStatus(): array;
}
