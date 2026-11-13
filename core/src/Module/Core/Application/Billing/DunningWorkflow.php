<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\DunningReminder;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Enum\InvoiceStatus;
use App\Repository\DunningReminderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use App\Module\Core\Domain\Enum\DunningStatus;

final class DunningWorkflow
{
    public function __construct(
        private readonly DunningReminderRepository $reminderRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly DunningNotificationSenderInterface $notificationSender,
        private readonly BillingSettings $settings,
    ) {
    }

    public function apply(Invoice $invoice, ?\App\Module\Core\Domain\Entity\User $actor = null, ?\DateTimeImmutable $now = null): ?DunningReminder
    {
        if (in_array($invoice->getStatus(), [InvoiceStatus::Paid, InvoiceStatus::Cancelled], true)) {
            return null;
        }

        $now = $now ?? new \DateTimeImmutable();
        return $this->entityManager->wrapInTransaction(function () use ($invoice, $actor, $now): ?DunningReminder {
            $this->entityManager->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
            if (in_array($invoice->getStatus(), [InvoiceStatus::Paid, InvoiceStatus::Cancelled, InvoiceStatus::Draft], true) || $invoice->getOutstandingCents() === 0) {
                return null;
            }
            if ($invoice->getDueDate() > $now) {
                return null;
            }

            $latest = $this->reminderRepository->findLatestForInvoice($invoice);
            if ($latest !== null && $latest->getStatus() === DunningStatus::Pending) {
                if ($this->notificationSender->send($latest)) {
                    $latest->markSent($now);
                }
                return $latest;
            }
            if ($latest !== null && ($latest->getSentAt() ?? $latest->getCreatedAt())->modify(sprintf('+%d days', max(1, $latest->getGraceDays()))) > $now) {
                return null;
            }
            $step = $this->resolveNextStep($invoice);
            if ($step === null) {
                return null;
            }

            $reminder = new DunningReminder($invoice, $step->level, $step->feeCents, $step->graceDays, $now);
            if ($step->feeCents > 0) {
                $invoice->addFee($step->feeCents);
            }

            $invoice->setStatus(InvoiceStatus::PastDue);
            $invoice->addReminder($reminder);

            $this->entityManager->persist($reminder);
            $notificationQueued = $this->notificationSender->send($reminder);
            if ($notificationQueued) {
                $reminder->markSent($now);
            }

            $this->auditLogger->log($actor, $notificationQueued ? 'billing.dunning.sent' : 'billing.dunning.created', [
                'invoice_id' => $invoice->getId(),
                'level' => $step->level,
                'fee_cents' => $step->feeCents,
                'grace_days' => $step->graceDays,
                'notification_queued' => $notificationQueued,
            ]);

            return $reminder;
        });
    }

    private function resolveNextStep(Invoice $invoice): ?DunningStep
    {
        $options = $this->settings->get();
        $steps = [];
        foreach ($options['reminder_fees'] as $index => $fee) {
            $steps[] = new DunningStep($index + 1, BillingAmount::parse((string) $fee), (int) $options['reminder_grace_days']);
        }

        $latest = $this->reminderRepository->findLatestForInvoice($invoice);
        $nextLevel = $latest ? $latest->getLevel() + 1 : 1;

        foreach ($steps as $step) {
            if ($step->level === $nextLevel) {
                return $step;
            }
        }

        return null;
    }
}
