<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use App\Message\RunBackupPlanMessage;
use Cron\CronExpression;
use Symfony\Component\Messenger\MessageBusInterface;

final class BackupScheduleDispatcher
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    /** @param iterable<BackupPlan> $plans */
    public function dispatchDue(iterable $plans): int
    {
        $now = new \DateTimeImmutable();
        $count = 0;

        foreach ($plans as $plan) {
            $expr = $plan->cronExpression();
            if ($expr === null || trim($expr) === '') {
                continue;
            }

            if (!CronExpression::isValidExpression($expr)) {
                continue;
            }

            $tz = new \DateTimeZone($plan->timeZone() ?: 'UTC');
            $nowLocal = $now->setTimezone($tz);
            $cron = CronExpression::factory($expr);
            $previousRun = $cron->getPreviousRunDate($nowLocal, 0, true);

            // Idempotency key = plan + the cron window being triggered.
            // The handler deduplicates using this key so re-running the command
            // within the same cron window is safe.
            $idempotencyKey = $plan->id() . ':' . $previousRun->format('YmdHi');

            $this->bus->dispatch(new RunBackupPlanMessage($plan->id(), false, $idempotencyKey));
            $count++;
        }

        return $count;
    }
}
