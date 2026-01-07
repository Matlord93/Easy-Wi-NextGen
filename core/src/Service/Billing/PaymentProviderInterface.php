<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Invoice;

interface PaymentProviderInterface
{
    public function getName(): string;

    public function createPaymentInstruction(Invoice $invoice, int $amountCents): PaymentInstruction;
}
