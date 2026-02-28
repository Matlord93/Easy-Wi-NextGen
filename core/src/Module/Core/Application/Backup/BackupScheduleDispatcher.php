<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use App\Message\RunBackupPlanMessage;
use Symfony\Component\Messenger\MessageBusInterface;

final class BackupScheduleDispatcher
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    /** @param iterable<BackupPlan> $plans */
    public function dispatchDue(iterable $plans): int
    {
        $count = 0;
        foreach ($plans as $plan) {
            if ($plan->cronExpression() === null || trim($plan->cronExpression()) === '') {
                continue;
            }

            $this->bus->dispatch(new RunBackupPlanMessage($plan->id(), false));
            $count++;
        }

        return $count;
    }
}
