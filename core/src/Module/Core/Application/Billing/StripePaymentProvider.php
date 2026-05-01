<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Enum\PaymentStatus;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stripe payment provider using Checkout Sessions (no Stripe SDK required).
 *
 * Required environment variables:
 *   STRIPE_SECRET_KEY    – secret API key (sk_live_... or sk_test_...)
 *   STRIPE_WEBHOOK_SECRET – signing secret from the Stripe dashboard (whsec_...)
 *
 * Optional:
 *   STRIPE_SUCCESS_URL   – redirect URL after successful payment (default /billing)
 *   STRIPE_CANCEL_URL    – redirect URL on cancel (default /billing)
 */
final class StripePaymentProvider implements PaymentProviderInterface
{
    private const API_BASE = 'https://api.stripe.com/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PaymentRecorder $paymentRecorder,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default:app.stripe_secret_key_default:STRIPE_SECRET_KEY)%')]
        private readonly string $secretKey,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default:app.stripe_webhook_secret_default:STRIPE_WEBHOOK_SECRET)%')]
        private readonly string $webhookSecret,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default:app.stripe_success_url_default:STRIPE_SUCCESS_URL)%')]
        private readonly string $successUrl,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default:app.stripe_cancel_url_default:STRIPE_CANCEL_URL)%')]
        private readonly string $cancelUrl,
    ) {
    }

    public function getName(): string
    {
        return 'stripe';
    }

    public function supportedMethods(): array
    {
        return ['card', 'sepa_debit', 'klarna', 'bancontact', 'ideal'];
    }

    public function createPaymentIntent(Invoice $invoice, int $amountCents): PaymentInstruction
    {
        if ($this->secretKey === '') {
            throw new \RuntimeException('Stripe secret key is not configured (STRIPE_SECRET_KEY).');
        }

        $response = $this->httpClient->request('POST', self::API_BASE . '/checkout/sessions', [
            'auth_basic' => [$this->secretKey, ''],
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'payment_method_types[]' => 'card',
                'line_items[0][price_data][currency]'                  => strtolower($invoice->getCurrency()),
                'line_items[0][price_data][unit_amount]'               => $amountCents,
                'line_items[0][price_data][product_data][name]'        => sprintf('Invoice %s', $invoice->getNumber()),
                'line_items[0][quantity]'                              => 1,
                'mode'                                                 => 'payment',
                'client_reference_id'                                  => $invoice->getNumber(),
                'success_url'                                          => $this->successUrl ?: '/billing',
                'cancel_url'                                           => $this->cancelUrl ?: '/billing',
            ]),
        ]);

        $status = $response->getStatusCode();
        $body = $response->toArray(false);

        if ($status < 200 || $status >= 300) {
            $error = $body['error']['message'] ?? 'unknown error';
            throw new \RuntimeException(sprintf('Stripe Checkout Session creation failed (%d): %s', $status, $error));
        }

        $sessionId  = (string) ($body['id'] ?? '');
        $sessionUrl = (string) ($body['url'] ?? '');

        return new PaymentInstruction(
            $this->getName(),
            $sessionId,
            [
                'session_id'   => $sessionId,
                'amount_cents' => $amountCents,
                'currency'     => $invoice->getCurrency(),
                'invoice'      => $invoice->getNumber(),
            ],
            $sessionUrl !== '' ? $sessionUrl : null,
        );
    }

    public function webhookHandle(Request $request): ?Response
    {
        $payload   = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature', '');

        if (!$this->verifyWebhookSignature($payload, $sigHeader)) {
            $this->logger->warning('stripe.webhook.signature_invalid');
            return new JsonResponse(['error' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return new JsonResponse(['error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $eventType = (string) ($event['type'] ?? '');

        if ($eventType === 'checkout.session.completed') {
            $this->handleCheckoutCompleted($event['data']['object'] ?? []);
            $this->entityManager->flush();
        } elseif ($eventType === 'payment_intent.payment_failed') {
            $this->handlePaymentFailed($event['data']['object'] ?? []);
        }

        return new JsonResponse(['received' => true]);
    }

    public function reconcile(?\DateTimeImmutable $since = null): void
    {
        if ($this->secretKey === '') {
            return;
        }

        $params = ['limit' => 100, 'expand[]' => 'data.payment_intent'];
        if ($since !== null) {
            $params['created[gte]'] = $since->getTimestamp();
        }

        try {
            $response = $this->httpClient->request('GET', self::API_BASE . '/checkout/sessions?' . http_build_query($params), [
                'auth_basic' => [$this->secretKey, ''],
            ]);

            $body = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('stripe.reconcile.fetch_failed', ['error' => $e->getMessage()]);
            return;
        }

        foreach ($body['data'] ?? [] as $session) {
            if (!is_array($session)) {
                continue;
            }
            if (($session['payment_status'] ?? '') !== 'paid') {
                continue;
            }
            $this->handleCheckoutCompleted($session);
        }

        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handleCheckoutCompleted(array $session): void
    {
        $invoiceNumber = (string) ($session['client_reference_id'] ?? '');
        $sessionId     = (string) ($session['id'] ?? '');
        $amountTotal   = (int) ($session['amount_total'] ?? 0);
        $currency      = strtoupper((string) ($session['currency'] ?? 'EUR'));

        if ($invoiceNumber === '' || $sessionId === '') {
            return;
        }

        $invoice = $this->invoiceRepository->findOneBy(['number' => $invoiceNumber]);
        if ($invoice === null) {
            $this->logger->warning('stripe.webhook.invoice_not_found', ['invoice' => $invoiceNumber]);
            return;
        }

        $existingPayment = null;
        foreach ($invoice->getPayments() as $payment) {
            if ($payment->getProvider() === $this->getName() && $payment->getReference() === $sessionId) {
                $existingPayment = $payment;
                break;
            }
        }

        if ($existingPayment !== null) {
            return;
        }

        try {
            $this->paymentRecorder->record(
                $invoice,
                $this->getName(),
                $sessionId,
                $amountTotal,
                $currency,
                PaymentStatus::Succeeded,
                null,
                new \DateTimeImmutable(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('stripe.webhook.record_failed', [
                'session' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $paymentIntent
     */
    private function handlePaymentFailed(array $paymentIntent): void
    {
        $this->logger->info('stripe.payment_intent.failed', [
            'id' => $paymentIntent['id'] ?? null,
        ]);
    }

    private function verifyWebhookSignature(string $payload, string $sigHeader): bool
    {
        if ($this->webhookSecret === '') {
            return false;
        }

        $timestamp  = null;
        $signatures = [];

        foreach (explode(',', $sigHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            [$key, $value] = $kv;
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);

        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
