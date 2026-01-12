<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\DunningReminder;
use App\Entity\Invoice;
use App\Enum\InvoiceStatus;
use App\Repository\DunningReminderRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class DunningWorkflow
{
    /**
     * @param DunningStep[] $steps
     */
    public function __construct(
        private readonly DunningReminderRepository $reminderRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly array $steps = [],
    ) {
    }

    public function apply(Invoice $invoice, ?\App\Entity\User $actor = null, ?\DateTimeImmutable $now = null): ?DunningReminder
    {
        if (in_array($invoice->getStatus(), [InvoiceStatus::Paid, InvoiceStatus::Cancelled], true)) {
            return null;
        }

        $now = $now ?? new \DateTimeImmutable();
        if ($invoice->getDueDate() > $now) {
            return null;
        }

        $step = $this->resolveNextStep($invoice);
        if ($step === null) {
            return null;
        }

        $reminder = new DunningReminder($invoice, $step->level, $step->feeCents, $step->graceDays);
        $reminder->markSent();

        if ($step->feeCents > 0) {
            $invoice->addFee($step->feeCents);
        }

        if ($step->graceDays > 0) {
            $invoice->extendDueDate($step->graceDays);
        }

        $invoice->setStatus(InvoiceStatus::PastDue);
        $invoice->addReminder($reminder);

        $this->entityManager->persist($reminder);
        $this->auditLogger->log($actor, 'billing.dunning.sent', [
            'invoice_id' => $invoice->getId(),
            'level' => $step->level,
            'fee_cents' => $step->feeCents,
            'grace_days' => $step->graceDays,
        ]);

        return $reminder;
    }

    private function resolveNextStep(Invoice $invoice): ?DunningStep
    {
        $steps = $this->steps ?: [
            new DunningStep(1, 0, 7),
            new DunningStep(2, 500, 7),
            new DunningStep(3, 1000, 7),
        ];

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
