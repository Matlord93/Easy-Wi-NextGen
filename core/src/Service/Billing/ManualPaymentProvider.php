<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Invoice;

final class ManualPaymentProvider implements PaymentProviderInterface
{
    public function getName(): string
    {
        return 'manual';
    }

    public function createPaymentInstruction(Invoice $invoice, int $amountCents): PaymentInstruction
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
}
