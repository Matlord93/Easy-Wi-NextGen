<?php

declare(strict_types=1);

namespace App\Module\Billing\UI\Controller\Public;

use App\Module\Core\Application\Billing\PaymentProviderRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives inbound webhook events from payment providers (Stripe, etc.).
 * Route: POST /billing/webhook/{provider}
 *
 * This endpoint must be publicly accessible (no authentication) because it is
 * called directly by the payment provider. Signature verification is delegated
 * to each provider implementation.
 */
#[Route(path: '/billing/webhook')]
final class PaymentWebhookController
{
    public function __construct(
        private readonly PaymentProviderRegistry $providerRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/{provider}', name: 'payment_webhook', methods: ['POST'])]
    public function handle(Request $request, string $provider): Response
    {
        $providerService = $this->providerRegistry->get($provider);

        if ($providerService === null) {
            $this->logger->warning('payment.webhook.unknown_provider', ['provider' => $provider]);
            return new JsonResponse(['error' => 'Unknown provider.'], Response::HTTP_NOT_FOUND);
        }

        $this->logger->info('payment.webhook.received', ['provider' => $provider]);

        try {
            $response = $providerService->webhookHandle($request);
        } catch (\Throwable $e) {
            $this->logger->error('payment.webhook.handler_failed', [
                'provider' => $provider,
                'error'    => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Webhook processing failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $response ?? new JsonResponse(['received' => true]);
    }
}
