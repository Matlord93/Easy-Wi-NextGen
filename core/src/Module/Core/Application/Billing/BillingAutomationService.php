<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class BillingAutomationService
{
    public function __construct(private InvoiceRepository $invoices, private InvoiceStatusUpdater $statuses, private DunningWorkflow $dunning, private BillingSettings $settings, private PaymentProviderRegistry $providers, private EntityManagerInterface $em, private EventDispatcherInterface $events, private \App\Module\Core\Application\AppSettingsService $appSettings)
    {
    }

    /** @return array{invoices: int, reminders: int} */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $this->events->dispatch(new \App\Extension\Event\BillingBeforeAutomationEvent($now), 'extension.billing.before_automation');
        $last = $this->appSettings->getSettings()[\App\Module\Core\Application\AppSettingsService::KEY_BILLING_LAST_RUN_AT] ?? null;
        $since = is_string($last) ? (new \DateTimeImmutable($last))->modify('-5 minutes') : null;
        foreach ($this->providers->all() as $provider) {
            $provider->reconcile($since);
        }
        $processed = 0;
        foreach ($this->invoices->findAll() as $invoice) {
            $this->statuses->syncStatus($invoice, null, $now);
            ++$processed;
        }
        $this->em->flush();
        $reminders = 0;
        if ($this->settings->get()['reminders_enabled']) {
            foreach ($this->invoices->findDunnable($now) as $invoice) {
                if ($this->dunning->apply($invoice, null, $now) !== null) {
                    ++$reminders;
                }
            }
        }
        $this->em->flush();

        return ['invoices' => $processed, 'reminders' => $reminders];
    }
}
