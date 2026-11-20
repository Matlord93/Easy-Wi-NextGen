<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Application\Contracts\RentalContracts;
use Symfony\Component\HttpFoundation\Request;

final readonly class RentalScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(private RentalContracts $contracts, private PaymentSettings $settings, private \App\Module\Core\Application\Contracts\RentalOverview $overview, private \App\Module\Core\Application\Contracts\RentalNotices $notices)
    {
    }

    #[\Override]
    public function type(): string
    {
        return 'rentals.usage';
    }

    #[\Override]
    public function schedules(): array
    {
        return [new InternalSchedule('system', 'rentals.usage', 'Minutengenaue Mietverträge', $this->type(), 'billing', '* * * * *', true)];
    }

    #[\Override]
    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return $this->runNow('system', 'rentals.usage', $now);
    }

    #[\Override]
    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        if ('system' !== $source || 'rentals.usage' !== $id) {
            return ScheduleExecutionResult::failed('Unknown rental schedule.');
        }
        // Disabling new sales must not disable cancellation, wallet limits or existing obligations.
        $this->settings->reset();
        $now ??= new \DateTimeImmutable();
        $count = $this->contracts->run($now, Request::create($this->settings->publicSettings()['base_url'].'/shop'));

        $this->overview->observe($now);
        $this->notices->deliver($now);

        return ScheduleExecutionResult::success(sprintf('%d Mietverträge geprüft.', $count));
    }
}
