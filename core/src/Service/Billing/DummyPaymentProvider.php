<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Invoice;

final class DummyPaymentProvider implements PaymentProviderInterface
{
    public function getName(): string
    {
        return 'dummy';
    }

    public function createPaymentInstruction(Invoice $invoice, int $amountCents): PaymentInstruction
    {
        return new PaymentInstruction(
            $this->getName(),
            sprintf('DUMMY-%s', $invoice->getNumber()),
            [
                'message' => 'Placeholder provider (no real payment executed).',
                'amount_cents' => $amountCents,
                'currency' => $invoice->getCurrency(),
            ],
            '/payments/dummy',
        );
    }
}
