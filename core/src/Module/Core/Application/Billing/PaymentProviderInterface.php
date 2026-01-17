<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Domain\Entity\Invoice;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface PaymentProviderInterface
{
    public function getName(): string;

    /**
     * @return string[]
     */
    public function supportedMethods(): array;

    public function createPaymentIntent(Invoice $invoice, int $amountCents): PaymentInstruction;

    public function webhookHandle(Request $request): ?Response;

    public function reconcile(?\DateTimeImmutable $since = null): void;
}
