<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Webhook;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WebhookDispatcher
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private WebhookUrlValidator $urlValidator,
        private LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $data @return array{status: int, delivery_id: string} */
    public function dispatch(string $url, #[\SensitiveParameter] string $secret, string $event, array $data): array
    {
        if ('' === $secret || 1 !== preg_match('/^[a-z][a-z0-9_.-]{2,119}$/', $event)) {
            throw new \InvalidArgumentException('A webhook secret and valid event name are required.');
        }
        $resolvedIp = $this->urlValidator->validate($url);
        $deliveryId = bin2hex(random_bytes(16));
        $timestamp = time();
        $body = json_encode(['id' => $deliveryId, 'event' => $event, 'created_at' => gmdate(DATE_ATOM, $timestamp), 'data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        $host = (string) parse_url($url, PHP_URL_HOST);

        $response = $this->httpClient->request('POST', $url, [
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Easy-Wi-Webhook/1.0',
                'X-EasyWi-Event' => $event,
                'X-EasyWi-Delivery' => $deliveryId,
                'X-EasyWi-Timestamp' => (string) $timestamp,
                'X-EasyWi-Signature' => 'sha256='.$signature,
            ],
            'timeout' => 10,
            'max_redirects' => 0,
            'resolve' => [$host => $resolvedIp],
        ]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->logger->warning('webhook.delivery_failed', ['delivery_id' => $deliveryId, 'event' => $event, 'status' => $status]);
            throw new \RuntimeException(sprintf('Webhook delivery failed with HTTP %d.', $status));
        }
        $this->logger->info('webhook.delivered', ['delivery_id' => $deliveryId, 'event' => $event, 'status' => $status]);

        return ['status' => $status, 'delivery_id' => $deliveryId];
    }
}
