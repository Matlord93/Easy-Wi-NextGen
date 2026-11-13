<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Scheduler;

use App\Module\Core\Application\Scheduler\PlaceholderScheduleHandler;

final class GameserverWatchdogScheduleHandler extends PlaceholderScheduleHandler
{
    #[\Override]
    public function type(): string { return 'gameserver.watchdog'; }
    #[\Override]
    protected function label(): string { return 'Gameserver Watchdog'; }
    #[\Override]
    protected function module(): string { return 'gameserver'; }
}
