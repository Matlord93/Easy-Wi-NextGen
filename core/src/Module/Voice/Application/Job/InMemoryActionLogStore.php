<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Job;

use App\Module\Voice\Application\Model\ActionLog;

final class InMemoryActionLogStore implements ActionLogStoreInterface
{
    /** @var list<ActionLog> */
    private array $logs = [];

    public function append(ActionLog $log): void
    {
        $this->logs[] = $log;
    }

    /** @return list<ActionLog> */
    public function all(): array
    {
        return $this->logs;
    }
}
