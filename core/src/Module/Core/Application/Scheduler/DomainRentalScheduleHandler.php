<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Application\Registrar\DomainAutomation;
use Symfony\Component\HttpFoundation\Request;

final readonly class DomainRentalScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(private DomainAutomation $domains, private PaymentSettings $settings)
    {
    }

    #[\Override]
    public function type(): string
    {
        return 'rentals.domains';
    }

    #[\Override]
    public function schedules(): array
    {
        return [new InternalSchedule('system', 'rentals.domains', 'Domainregistrierung und Verlängerung', $this->type(), 'billing', '* * * * *', true)];
    }

    #[\Override]
    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return $this->runNow('system', 'rentals.domains', $now);
    }

    #[\Override]
    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        if ('system' !== $source || 'rentals.domains' !== $id) {
            return ScheduleExecutionResult::failed('Unknown domain schedule.');
        }
        $this->settings->reset();
        $this->domains->run($now ?? new \DateTimeImmutable(), Request::create($this->settings->publicSettings()['base_url'].'/shop'));

        return ScheduleExecutionResult::success('Domainaufträge abgeglichen.');
    }
}
