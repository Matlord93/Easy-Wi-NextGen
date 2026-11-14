<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Domain\Entity\Invoice;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ManualPaymentProvider implements PaymentProviderInterface
{
    #[\Override]
    public function getName(): string
    {
        return 'manual';
    }

    /** @return list<string> */
    #[\Override]
    public function supportedMethods(): array
    {
        return ['bank_transfer'];
    }

    #[\Override]
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

    #[\Override]
    public function webhookHandle(Request $request): ?Response
    {
        return null;
    }

    #[\Override]
    public function reconcile(?\DateTimeImmutable $since = null): void
    {
    }
}
