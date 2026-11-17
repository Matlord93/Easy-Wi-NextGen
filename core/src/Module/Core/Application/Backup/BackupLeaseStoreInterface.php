<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

interface BackupLeaseStoreInterface extends BackupPlanStoreInterface
{
    /** Only a live lease owned by this worker can be renewed. */
    public function renewLock(string $scope, \DateTimeImmutable $expiresAt): bool;
}
