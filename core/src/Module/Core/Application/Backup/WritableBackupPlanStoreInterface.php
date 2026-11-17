<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

interface WritableBackupPlanStoreInterface extends BackupPlanStoreInterface
{
    public function save(BackupPlan $plan): void;

    public function delete(string $planId): bool;
}
