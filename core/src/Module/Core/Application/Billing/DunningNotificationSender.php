<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Application\MailService;
use App\Module\Core\Domain\Entity\DunningReminder;
use App\Repository\InvoicePreferencesRepository;
use Psr\Log\LoggerInterface;

final class DunningNotificationSender
{
    public function __construct(
        private readonly MailService $mailService,
        private readonly InvoicePreferencesRepository $preferencesRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(DunningReminder $reminder): bool
    {
        $invoice = $reminder->getInvoice();
        $customer = $invoice->getCustomer();
        $preferences = $this->preferencesRepository->findOneByCustomer($customer);

        if ($preferences !== null && !$preferences->isEmailDelivery()) {
            $this->logger->info('billing.dunning.email_skipped', [
                'invoice_id' => $invoice->getId(),
                'reason' => 'customer_email_delivery_disabled',
            ]);

            return false;
        }

        try {
            return $this->mailService->sendTemplate($customer->getEmail(), 'invoice_reminder', [
                'invoice_number' => $invoice->getNumber(),
                'invoice_amount' => number_format($invoice->getAmountDueCents() / 100, 2, '.', ''),
                'invoice_currency' => $invoice->getCurrency(),
                'due_date' => $invoice->getDueDate(),
                'reminder_level' => $reminder->getLevel(),
                'reminder_fee' => number_format($reminder->getFeeCents() / 100, 2, '.', ''),
            ], $preferences?->getLocale(), true);
        } catch (\Throwable $exception) {
            $this->logger->error('billing.dunning.email_queue_failed', [
                'invoice_id' => $invoice->getId(),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
