<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Musicbot\Domain\Entity\MusicbotSchedule;

interface MusicbotScheduleDispatcherInterface
{
    public function dispatch(MusicbotSchedule $schedule): AgentJob;
}
