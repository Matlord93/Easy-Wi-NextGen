<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Application\ModuleRegistry;
use App\Module\Nodes\Application\Proxmox\ProxmoxTaskSynchronizer;

final readonly class ProxmoxTasksScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(private ModuleRegistry $modules, private ProxmoxTaskSynchronizer $tasks)
    {
    }

    #[\Override]
    public function type(): string
    {
        return 'proxmox.tasks';
    }

    /** @return list<InternalSchedule> */
    #[\Override]
    public function schedules(): array
    {
        return [new InternalSchedule('system', $this->type(), 'Proxmox-Aktivitäten abgleichen', $this->type(), 'proxmox', '* * * * *', $this->modules->isEnabled('proxmox'))];
    }

    #[\Override]
    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        if (!$this->modules->isEnabled('proxmox')) {
            return ScheduleExecutionResult::skipped('Proxmox ist deaktiviert.');
        }
        $result = $this->tasks->run(25);
        if ($result['errors'] > 0) {
            return ScheduleExecutionResult::failed(sprintf('%d Proxmox-Aufträge derzeit nicht erreichbar.', $result['errors']));
        }

        return $result['checked'] > 0 ? ScheduleExecutionResult::success(sprintf('%d Proxmox-Aufträge geprüft.', $result['checked'])) : ScheduleExecutionResult::skipped('Keine offenen Proxmox-Aufträge.');
    }

    #[\Override]
    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return 'system' === $source && $id === $this->type() ? $this->runDue($now) : ScheduleExecutionResult::failed('Unbekannte Proxmox-Aufgabe.');
    }
}
