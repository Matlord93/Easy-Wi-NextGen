<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Invoice;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ManualPaymentProvider implements PaymentProviderInterface
{
    public function getName(): string
    {
        return 'manual';
    }

    public function supportedMethods(): array
    {
        return ['bank_transfer'];
    }

    public function createPaymentIntent(Invoice $invoice, int $amountCents): PaymentInstruction
    {
        return new PaymentInstruction(
            $this->getName(),
            sprintf('MANUAL-%s', $invoice->getNumber()),
            [
                'message' => 'Manual transfer required.',
                'amount_cents' => $amountCents,
                'currency' => $invoice->getCurrency(),
            ],
        );
    }

    public function webhookHandle(Request $request): ?Response
    {
        return null;
    }

    public function reconcile(?\DateTimeImmutable $since = null): void
    {
    }
}
