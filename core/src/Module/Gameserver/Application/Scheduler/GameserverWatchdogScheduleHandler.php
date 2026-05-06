<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Scheduler;

use App\Module\Core\Application\Scheduler\PlaceholderScheduleHandler;

final class GameserverWatchdogScheduleHandler extends PlaceholderScheduleHandler
{
    public function type(): string { return 'gameserver.watchdog'; }
    protected function label(): string { return 'Gameserver Watchdog'; }
    protected function module(): string { return 'gameserver'; }
}
