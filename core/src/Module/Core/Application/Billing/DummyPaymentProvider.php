<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Domain\Entity\Invoice;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DummyPaymentProvider implements PaymentProviderInterface
{
    public function getName(): string
    {
        return 'dummy';
    }

    public function supportedMethods(): array
    {
        return ['test_card', 'test_transfer'];
    }

    public function createPaymentIntent(Invoice $invoice, int $amountCents): PaymentInstruction
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

    public function webhookHandle(Request $request): ?Response
    {
        return new Response('Dummy provider has no webhooks.', Response::HTTP_NOT_IMPLEMENTED);
    }

    public function reconcile(?\DateTimeImmutable $since = null): void
    {
    }
}
