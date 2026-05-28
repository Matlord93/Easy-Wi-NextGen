<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Application\AgentUpdateQueueService;
use App\Module\Setup\Application\WebinterfaceUpdateService;
use App\Module\Setup\Application\WebinterfaceUpdateSettingsService;

final class WebinterfaceAutoUpdateScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(
        private readonly WebinterfaceUpdateSettingsService $settingsService,
        private readonly WebinterfaceUpdateService $updateService,
        private readonly AgentUpdateQueueService $agentUpdateQueueService,
    ) {
    }

    public function type(): string { return 'webinterface.auto_update'; }
    public function schedules(): array
    {
        $s = $this->settingsService->getSettings();
        return [new InternalSchedule('system', 'webinterface_auto_update', 'Webinterface Auto Update', $this->type(), 'core', '*/5 * * * *', (bool) $s['autoEnabled'], ['autoMigrate' => (bool) $s['autoMigrate'], 'coreChannel' => $s['coreChannel'], 'agentChannel' => $s['agentChannel']])];
    }
    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult { return $this->runInternal(); }
    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult { return $this->runInternal(true); }

    private function runInternal(bool $force = false): ScheduleExecutionResult
    {
        $settings = $this->settingsService->getSettings();
        if (!$settings['autoEnabled'] && !$force) {
            return ScheduleExecutionResult::skipped('Auto update disabled.');
        }
        $agentResult = $this->agentUpdateQueueService->queueAvailableUpdates($settings['agentChannel'], $force);
        $status = $this->updateService->checkForUpdate($force);
        if ($status->updateAvailable !== true) {
            return ScheduleExecutionResult::success('No core update available.', []);
        }
        $result = $this->updateService->applyUpdate();
        if (!$result->success) {
            return ScheduleExecutionResult::failed($result->error ?? $result->message);
        }
        return ScheduleExecutionResult::success('Core updated; queued agent updates: ' . ($agentResult['queued'] ?? 0), []);
    }
}

