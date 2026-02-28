<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Job;

use App\Module\Voice\Application\Model\ActionLog;

interface ActionLogStoreInterface
{
    public function append(ActionLog $log): void;
}
