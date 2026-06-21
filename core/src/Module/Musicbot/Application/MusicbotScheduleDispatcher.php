<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Musicbot\Domain\Entity\MusicbotSchedule;

final class MusicbotScheduleDispatcher implements MusicbotScheduleDispatcherInterface
{
    public function __construct(
        private readonly AgentJobDispatcher $jobDispatcher,
    ) {
    }

    public function dispatch(MusicbotSchedule $schedule): AgentJob
    {
        $instance = $schedule->getInstance();

        return $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.schedule.action', [
            'instance_id' => (string) $instance->getId(),
            'schedule_id' => (string) $schedule->getId(),
            'action' => $schedule->getAction()->value,
            'service_name' => $instance->getServiceName(),
            'install_path' => $instance->getInstallPath(),
            'payload' => $schedule->getPayload() ?? [],
        ]);
    }
}
