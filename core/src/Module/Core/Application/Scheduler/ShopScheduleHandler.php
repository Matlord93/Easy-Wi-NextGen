<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Application\ModuleRegistry;
use App\Module\Core\Application\ShopAutomationService;

final readonly class ShopScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(private ShopAutomationService $automation, private ModuleRegistry $modules)
    {
    }

    #[\Override]
    public function type(): string
    {
        return 'shop.automation';
    }

    #[\Override]
    public function schedules(): array
    {
        return [new InternalSchedule('system', 'shop.automation', 'Shop fulfillment and subscriptions', $this->type(), 'billing', '* * * * *', $this->modules->isEnabled('billing'))];
    }

    #[\Override]
    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return $this->runNow('system', 'shop.automation', $now);
    }

    #[\Override]
    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        if ('system' !== $source || 'shop.automation' !== $id) {
            return ScheduleExecutionResult::failed('Unknown shop schedule.');
        }
        if (!$this->modules->isEnabled('billing')) {
            return ScheduleExecutionResult::skipped('Billing disabled.');
        }

        return ScheduleExecutionResult::success(sprintf('Processed %d shop orders.', $this->automation->run()));
    }
}
