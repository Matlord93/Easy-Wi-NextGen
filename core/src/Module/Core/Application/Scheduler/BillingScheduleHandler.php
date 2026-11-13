<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\Billing\BillingAutomationService;
use App\Module\Core\Application\Billing\BillingSettings;

final readonly class BillingScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(private BillingAutomationService $automation, private BillingSettings $billingSettings, private AppSettingsService $settings)
    {
    }

    #[\Override]
    public function type(): string
    {
        return 'billing.automation';
    }

    #[\Override]
    public function schedules(): array
    {
        return [new InternalSchedule('system', 'billing.automation', 'Billing automation', $this->type(), 'billing', '0 * * * *', (bool) $this->billingSettings->get()['automation_enabled'])];
    }

    #[\Override]
    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        $now ??= new \DateTimeImmutable();
        $last = $this->settings->getSettings()[AppSettingsService::KEY_BILLING_LAST_RUN_AT] ?? null;
        if (is_string($last) && new \DateTimeImmutable($last) >= $now->setTime((int) $now->format('H'), 0)) {
            return ScheduleExecutionResult::skipped('Billing already processed this hour.');
        }

        return $this->runNow('system', 'billing.automation', $now);
    }

    #[\Override]
    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        if ($source !== 'system' || $id !== 'billing.automation') {
            return ScheduleExecutionResult::failed('Unknown billing schedule.');
        }
        if (!$this->billingSettings->get()['automation_enabled']) {
            return ScheduleExecutionResult::skipped('Billing automation disabled.');
        }
        $now ??= new \DateTimeImmutable();
        $result = $this->automation->run($now);
        $this->settings->updateSettings([AppSettingsService::KEY_BILLING_LAST_RUN_AT => $now->format(DATE_ATOM)]);

        return ScheduleExecutionResult::success(sprintf('Processed %d invoices and %d reminders.', $result['invoices'], $result['reminders']));
    }
}
