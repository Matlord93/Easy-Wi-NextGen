<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Config\ScalarValue;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\PaymentCheckout;
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
final class StripePaymentProvider implements PaymentProviderInterface, WalletPaymentProviderInterface
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
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default:app.default_uri_default:DEFAULT_URI)%')]
        private readonly string $baseUri = 'http://localhost',
        private readonly ?PaymentSettings $settings = null,
        private readonly ?\App\Module\Core\Application\Contracts\WalletFunds $walletFunds = null,
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return 'stripe';
    }

    /** @return list<string> */
    #[\Override]
    public function supportedMethods(): array
    {
        if (null !== $this->settings && $this->settings->isConfigured()) {
            return array_values(array_intersect(['card', 'klarna', 'sepa_debit'], $this->settings->methods()));
        }

        return '' !== $this->secretKey ? ['card'] : [];
    }

    #[\Override]
    public function createPaymentIntent(Invoice $invoice, int $amountCents, ?string $method = null): PaymentInstruction
    {
        return $this->createCheckout($invoice, $amountCents, $method);
    }

    #[\Override]
    public function createWalletPaymentIntent(\App\Module\Core\Domain\Entity\WalletTopup $topup, ?string $method = null): PaymentInstruction
    {
        if (null !== $topup->getPaidAt()) {
            throw new \DomainException('Diese Aufladung ist bereits bezahlt.');
        }

        return $this->createCheckout($topup, $topup->getAmountCents(), $method);
    }

    private function createCheckout(Invoice|\App\Module\Core\Domain\Entity\WalletTopup $invoice, int $amountCents, ?string $method): PaymentInstruction
    {
        if ('' === $this->key() || [] === $this->supportedMethods()) {
            throw new \RuntimeException('Stripe secret key is not configured (STRIPE_SECRET_KEY).');
        }

        if ($amountCents < 1 || ($invoice instanceof Invoice && $amountCents !== $invoice->getOutstandingCents()) || (null !== $method && !in_array($method, $this->supportedMethods(), true))) {
            throw new \InvalidArgumentException('Invalid Stripe payment amount or method.');
        }
        $response = $this->httpClient->request('POST', self::API_BASE.'/checkout/sessions', [
            'auth_basic' => [$this->key(), ''],
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'payment_method_types' => null !== $method ? [$method] : $this->supportedMethods(),
                'line_items[0][price_data][currency]' => strtolower($invoice->getCurrency()),
                'line_items[0][price_data][unit_amount]' => $amountCents,
                'line_items[0][price_data][product_data][name]' => ($invoice instanceof Invoice ? 'Rechnung ' : 'Guthabenaufladung ').$invoice->getNumber(),
                'line_items[0][quantity]' => 1,
                'mode' => 'payment',
                'client_reference_id' => $invoice->getNumber(),
                'success_url' => $this->absoluteUrl($invoice instanceof Invoice ? $this->successUrl : '/marketplace/wallet/'.$invoice->getId()),
                'cancel_url' => $this->absoluteUrl($invoice instanceof Invoice ? $this->cancelUrl : '/marketplace/wallet/'.$invoice->getId()),
            ]),
        ]);

        $status = $response->getStatusCode();
        $body = $response->toArray(false);

        if ($status < 200 || $status >= 300) {
            $error = ScalarValue::text(JsonArray::orEmpty($body['error'] ?? null)['message'] ?? 'unknown error');
            throw new \RuntimeException(sprintf('Stripe Checkout Session creation failed (%d): %s', $status, $error));
        }

        $sessionId = (string) ($body['id'] ?? '');
        $sessionUrl = (string) ($body['url'] ?? '');

        if (!preg_match('/^cs_[A-Za-z0-9_]+$/D', $sessionId) || 'https' !== parse_url($sessionUrl, PHP_URL_SCHEME) || 'checkout.stripe.com' !== parse_url($sessionUrl, PHP_URL_HOST)) {
            throw new \RuntimeException('Stripe returned an invalid Checkout Session.');
        }
        $this->entityManager->persist(new PaymentCheckout($invoice, 'stripe', $sessionId, $amountCents, $invoice->getCurrency()));
        $this->entityManager->flush();

        return new PaymentInstruction(
            $this->getName(),
            $sessionId,
            [
                'session_id' => $sessionId,
                'amount_cents' => $amountCents,
                'currency' => $invoice->getCurrency(),
                'invoice' => $invoice->getNumber(),
            ],
            '' !== $sessionUrl ? $sessionUrl : null,
        );
    }

    #[\Override]
    public function webhookHandle(Request $request): ?Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature', '');

        if (!$this->verifyWebhookSignature($payload, $sigHeader)) {
            $this->logger->warning('stripe.webhook.signature_invalid');

            return new JsonResponse(['error' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }

        $event = JsonArray::decode($payload);
        if (!is_array($event)) {
            return new JsonResponse(['error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $eventType = (string) ($event['type'] ?? '');

        if (in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $this->handleCheckoutCompleted(JsonMap::fromValue(JsonArray::orEmpty($event['data'] ?? null)['object'] ?? null) ?? []);
            $this->entityManager->flush();
        } elseif ('payment_intent.payment_failed' === $eventType) {
            $this->handlePaymentFailed(JsonMap::fromValue(JsonArray::orEmpty($event['data'] ?? null)['object'] ?? null) ?? []);
        }

        return new JsonResponse(['received' => true]);
    }

    #[\Override]
    public function reconcile(?\DateTimeImmutable $since = null): void
    {
        if ('' === $this->key() || [] === $this->supportedMethods()) {
            return;
        }

        $params = $this->reconcileParameters();
        if (null !== $since) {
            $params['created[gte]'] = $since->getTimestamp();
        }

        do {
            $response = $this->httpClient->request('GET', self::API_BASE.'/checkout/sessions?'.http_build_query($params), ['auth_basic' => [$this->key(), '']]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new \RuntimeException('Stripe reconciliation failed.');
            }
            $body = $response->toArray();
            $sessions = JsonArray::orEmpty($body['data'] ?? null);
            foreach (array_keys($sessions) as $index) {
                $session = JsonMap::fromValue($sessions[$index]);
                if (null !== $session && ($session['payment_status'] ?? '') === 'paid') {
                    $this->handleCheckoutCompleted($session);
                }
            }
            $hasMore = (bool) ($body['has_more'] ?? false);
            if ($hasMore) {
                $lastIndex = array_key_last($sessions);
                $last = null === $lastIndex ? '' : ScalarValue::text(JsonArray::orEmpty($sessions[$lastIndex])['id'] ?? null);
                if ('' === $last || $last === ($params['starting_after'] ?? null)) {
                    throw new \RuntimeException('Stripe returned an invalid pagination cursor.');
                }
                $params['starting_after'] = $last;
            }
        } while ($hasMore);
        $this->entityManager->flush();
    }

    /** @return array<string,int|string> */
    private function reconcileParameters(): array
    {
        return ['limit' => 100, 'expand[]' => 'data.payment_intent'];
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handleCheckoutCompleted(array $session): void
    {
        if (($session['payment_status'] ?? '') !== 'paid') {
            return;
        }
        $invoiceNumber = (string) ($session['client_reference_id'] ?? '');
        $sessionId = (string) ($session['id'] ?? '');
        $amountTotal = (int) ($session['amount_total'] ?? 0);
        $currency = strtoupper((string) ($session['currency'] ?? 'EUR'));

        if ('' === $invoiceNumber || '' === $sessionId) {
            return;
        }

        $checkout = $this->entityManager->getRepository(PaymentCheckout::class)->findOneBy(['provider' => 'stripe', 'reference' => $sessionId]);
        $topup = $checkout?->getTopup();
        if (null !== $checkout && null !== $topup) {
            if ($invoiceNumber !== $topup->getNumber() || $amountTotal !== $checkout->getAmountCents() || $currency !== $checkout->getCurrency() || null === $this->walletFunds) {
                throw new \DomainException('Stripe funding does not match its checkout.');
            }
            $this->walletFunds->confirm($checkout, $sessionId);

            return;
        }
        $invoice = $this->invoiceRepository->findOneBy(['number' => $invoiceNumber]);
        if (null === $invoice) {
            $this->logger->warning('stripe.webhook.invoice_not_found', ['invoice' => $invoiceNumber]);

            return;
        }

        $checkout = $this->entityManager->getRepository(PaymentCheckout::class)->findOneBy(['provider' => 'stripe', 'reference' => $sessionId]);
        if (null !== $checkout && ($checkout->getInvoice()->getId() !== $invoice->getId() || $checkout->getAmountCents() !== $amountTotal || $checkout->getCurrency() !== $currency)) {
            throw new \DomainException('Stripe payment does not match its checkout.');
        }
        if (null === $checkout && ($amountTotal < 1 || $amountTotal > $invoice->getAmountDueCents() || $currency !== $invoice->getCurrency())) {
            throw new \DomainException('Stripe payment does not match the invoice.');
        }
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
        $checkout?->complete();
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

    private function key(): string
    {
        return $this->settings?->secret('stripe_key', $this->secretKey) ?? $this->secretKey;
    }

    private function verifyWebhookSignature(string $payload, string $sigHeader): bool
    {
        if (($this->settings?->secret('stripe_webhook', $this->webhookSecret) ?? $this->webhookSecret) === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $sigHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (2 !== count($kv)) {
                continue;
            }
            [$key, $value] = $kv;
            if ('t' === $key) {
                $timestamp = $value;
            } elseif ('v1' === $key) {
                $signatures[] = $value;
            }
        }

        if (null === $timestamp || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || [] === $signatures) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $this->settings?->secret('stripe_webhook', $this->webhookSecret) ?? $this->webhookSecret);

        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    private function panelOrigin(): string
    {
        $origin = $this->settings?->publicSettings()['base_url'] ?? '';

        return '' !== $origin ? $origin : $this->baseUri;
    }

    private function absoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return $url;
        }

        return rtrim($this->panelOrigin(), '/').'/'.ltrim('' === $url || '/billing' === $url ? '/customer/billing' : $url, '/');
    }
}
