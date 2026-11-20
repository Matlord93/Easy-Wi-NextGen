<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Config\ScalarValue;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\PaymentCheckout;
use App\Module\Core\Domain\Enum\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class PaypalPaymentProvider implements PaymentProviderInterface, WalletPaymentProviderInterface
{
    public function __construct(private HttpClientInterface $http, private PaymentSettings $settings, private PaymentRecorder $recorder, private EntityManagerInterface $em, private ?\App\Module\Core\Application\Contracts\WalletFunds $walletFunds = null)
    {
    }

    #[\Override]
    public function getName(): string
    {
        return 'paypal';
    }

    #[\Override]
    public function supportedMethods(): array
    {
        return in_array('paypal', $this->settings->methods(), true) ? ['paypal'] : [];
    }

    #[\Override]
    public function createPaymentIntent(Invoice $invoice, int $amountCents): PaymentInstruction
    {
        return $this->createCheckout($invoice, $amountCents);
    }

    #[\Override]
    public function createWalletPaymentIntent(\App\Module\Core\Domain\Entity\WalletTopup $topup, ?string $method = null): PaymentInstruction
    {
        if (null !== $topup->getPaidAt() || (null !== $method && 'paypal' !== $method)) {
            throw new \DomainException('Ungültige oder bereits bezahlte Aufladung.');
        }

        return $this->createCheckout($topup, $topup->getAmountCents());
    }

    private function createCheckout(Invoice|\App\Module\Core\Domain\Entity\WalletTopup $invoice, int $amountCents): PaymentInstruction
    {
        if ([] === $this->supportedMethods() || $amountCents < 1 || ($invoice instanceof Invoice && $amountCents !== $invoice->getOutstandingCents())) {
            throw new \DomainException('PayPal is unavailable or the invoice amount changed.');
        }
        $checkout = new PaymentCheckout($invoice, 'paypal', 'pending-'.bin2hex(random_bytes(16)), $amountCents, $invoice->getCurrency());
        $origin = $this->settings->publicSettings()['base_url'];
        $body = $this->api('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [['custom_id' => $checkout->getId(), 'invoice_id' => $invoice->getNumber(), 'amount' => ['currency_code' => $invoice->getCurrency(), 'value' => self::decimal($amountCents)]]],
            'payment_source' => ['paypal' => ['experience_context' => ['user_action' => 'PAY_NOW', 'return_url' => $origin.'/billing/paypal/return/'.$checkout->getId(), 'cancel_url' => $origin.($invoice instanceof Invoice ? '/customer/billing' : '/marketplace/wallet/'.$invoice->getId())]]],
        ], $checkout->getId());
        $reference = ScalarValue::text($body['id'] ?? null);
        $url = '';
        $links = JsonArray::orEmpty($body['links'] ?? null);
        foreach (array_keys($links) as $index) {
            $link = JsonMap::fromValue($links[$index]) ?? [];
            if (in_array($link['rel'] ?? '', ['approve', 'payer-action'], true)) {
                $url = ScalarValue::text($link['href'] ?? null);
            }
        }
        if (!preg_match('/^[A-Z0-9]{8,120}$/D', $reference) || 'https' !== parse_url($url, PHP_URL_SCHEME) || !in_array(parse_url($url, PHP_URL_HOST), ['www.paypal.com', 'www.sandbox.paypal.com'], true)) {
            throw new \RuntimeException('Invalid PayPal order response.');
        }
        $checkout->setReference($reference);
        $this->em->persist($checkout);
        $this->em->flush();

        return new PaymentInstruction('paypal', $reference, ['invoice' => $invoice->getNumber()], $url);
    }

    /** A browser redirect never constitutes payment; capture is verified by the server. */
    public function capture(string $state, string $token): void
    {
        $checkout = $this->em->find(PaymentCheckout::class, $state);
        if (null === $checkout || 'paypal' !== $checkout->getProvider() || !hash_equals($checkout->getReference(), $token) || $checkout->getCreatedAt() < new \DateTimeImmutable('-1 day')) {
            throw new \DomainException('Invalid or expired PayPal checkout.');
        }
        if ($checkout->isCompleted()) {
            return;
        }
        $body = $this->api('POST', '/v2/checkout/orders/'.rawurlencode($token).'/capture', [], $checkout->getId().'-capture');
        $this->recordOrder($checkout, $body);
    }

    #[\Override]
    public function webhookHandle(Request $request): ?Response
    {
        foreach (['PAYPAL-AUTH-ALGO', 'PAYPAL-CERT-URL', 'PAYPAL-TRANSMISSION-ID', 'PAYPAL-TRANSMISSION-SIG', 'PAYPAL-TRANSMISSION-TIME'] as $header) {
            $value = $request->headers->get($header, '');
            if ('' === $value || strlen($value) > 4096) {
                return new JsonResponse(['error' => 'Missing or invalid signature headers.'], 401);
            }
        }
        $event = JsonMap::fromValue(JsonArray::decode($request->getContent()));
        if (null === $event) {
            return new JsonResponse(['error' => 'Invalid JSON.'], 400);
        }
        $verification = $this->api('POST', '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $request->headers->get('PAYPAL-AUTH-ALGO'), 'cert_url' => $request->headers->get('PAYPAL-CERT-URL'),
            'transmission_id' => $request->headers->get('PAYPAL-TRANSMISSION-ID'), 'transmission_sig' => $request->headers->get('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->headers->get('PAYPAL-TRANSMISSION-TIME'), 'webhook_id' => $this->settings->secret('paypal_webhook'), 'webhook_event' => $event,
        ]);
        if (($verification['verification_status'] ?? '') !== 'SUCCESS') {
            return new JsonResponse(['error' => 'Invalid signature.'], 401);
        }
        if (($event['event_type'] ?? '') === 'PAYMENT.CAPTURE.COMPLETED') {
            $resource = JsonMap::fromValue($event['resource'] ?? null) ?? [];
            $related = JsonMap::fromValue(JsonArray::orEmpty($resource['supplementary_data'] ?? null)['related_ids'] ?? null) ?? [];
            $checkout = $this->em->getRepository(PaymentCheckout::class)->findOneBy(['provider' => 'paypal', 'reference' => ScalarValue::text($related['order_id'] ?? null)]);
            if (null !== $checkout) {
                $this->recordCapture($checkout, $resource);
            }
        }

        return new JsonResponse(['received' => true]);
    }

    #[\Override]
    public function reconcile(?\DateTimeImmutable $since = null): void
    {
        if ([] === $this->supportedMethods()) {
            return;
        }
        /** @var list<PaymentCheckout> $checkouts */
        $checkouts = $this->em->getRepository(PaymentCheckout::class)->createQueryBuilder('c')->where('c.provider = :provider AND c.completed = false AND c.createdAt >= :since')->setParameter('provider', 'paypal')->setParameter('since', $since ?? new \DateTimeImmutable('-7 days'))->setMaxResults(100)->getQuery()->getResult();
        foreach ($checkouts as $checkout) {
            $this->recordOrder($checkout, $this->api('GET', '/v2/checkout/orders/'.rawurlencode($checkout->getReference())));
        }
    }

    /** @param array<string, mixed> $order */
    private function recordOrder(PaymentCheckout $checkout, array $order): void
    {
        if (($order['status'] ?? '') !== 'COMPLETED' || ($order['id'] ?? '') !== $checkout->getReference()) {
            return;
        }
        $units = JsonArray::orEmpty($order['purchase_units'] ?? null);
        if (1 !== count($units)) {
            throw new \DomainException('Unexpected PayPal purchase units.');
        }
        $unit = JsonMap::fromValue(reset($units)) ?? [];
        if (($unit['custom_id'] ?? '') !== $checkout->getId()) {
            throw new \DomainException('PayPal checkout reference mismatch.');
        }
        $captures = JsonArray::orEmpty(JsonArray::orEmpty($unit['payments'] ?? null)['captures'] ?? null);
        if (1 !== count($captures)) {
            throw new \DomainException('Unexpected PayPal captures.');
        }
        $this->recordCapture($checkout, JsonMap::fromValue(reset($captures)) ?? []);
    }

    /** @param array<string, mixed> $capture */
    private function recordCapture(PaymentCheckout $checkout, array $capture): void
    {
        $amount = JsonMap::fromValue($capture['amount'] ?? null) ?? [];
        $reference = ScalarValue::text($capture['id'] ?? null);
        if (($capture['status'] ?? '') !== 'COMPLETED' || '' === $reference || ($amount['currency_code'] ?? '') !== $checkout->getCurrency() || ($amount['value'] ?? '') !== self::decimal($checkout->getAmountCents())) {
            throw new \DomainException('PayPal capture does not match the checkout.');
        }
        $this->em->wrapInTransaction(function () use ($checkout, $reference): void {
            if (null !== $checkout->getTopup()) {
                if (null === $this->walletFunds) {
                    throw new \DomainException('Wallet funding is unavailable.');
                }
                $this->walletFunds->confirm($checkout, $reference);

                return;
            }
            $this->recorder->record($checkout->getInvoice(), 'paypal', $reference, $checkout->getAmountCents(), $checkout->getCurrency(), PaymentStatus::Succeeded, null, new \DateTimeImmutable());
            $checkout->complete();
        });
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, ?array $body = null, ?string $idempotency = null): array
    {
        $base = $this->settings->publicSettings()['paypal_sandbox'] ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $tokenResponse = $this->http->request('POST', $base.'/v1/oauth2/token', ['auth_basic' => [$this->settings->secret('paypal_client'), $this->settings->secret('paypal_secret')], 'body' => ['grant_type' => 'client_credentials'], 'timeout' => 20, 'max_redirects' => 0]);
        $token = ScalarValue::text($tokenResponse->toArray()['access_token'] ?? null);
        if ('' === $token) {
            throw new \RuntimeException('PayPal authentication failed.');
        }
        $options = ['auth_bearer' => $token, 'timeout' => 30, 'max_redirects' => 0, 'headers' => ['Content-Type' => 'application/json']];
        if (null !== $body) {
            $options['body'] = json_encode([] === $body ? new \stdClass() : $body, JSON_THROW_ON_ERROR);
        }
        if (null !== $idempotency) {
            $options['headers']['PayPal-Request-Id'] = $idempotency;
        }

        return JsonMap::fromValue($this->http->request($method, $base.$path, $options)->toArray()) ?? [];
    }

    private static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
