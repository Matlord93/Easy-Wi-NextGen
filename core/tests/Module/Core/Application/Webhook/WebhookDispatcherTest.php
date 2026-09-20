<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Webhook;

use App\Module\Core\Application\Webhook\WebhookDispatcher;
use App\Module\Core\Application\Webhook\WebhookUrlValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebhookDispatcherTest extends TestCase
{
    public function testSignsPayloadAndDisablesRedirects(): void
    {
        $validator = $this->createMock(WebhookUrlValidator::class);
        $validator->method('validate')->willReturn('93.184.216.34');
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://hooks.example.com/events', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(['hooks.example.com' => '93.184.216.34'], $options['resolve']);
            self::assertMatchesRegularExpression('/^X-EasyWi-Signature: sha256=[a-f0-9]{64}$/', $options['normalized_headers']['x-easywi-signature'][0]);
            return new MockResponse('', ['http_code' => 204]);
        });
        $result = (new WebhookDispatcher($http, $validator, new NullLogger()))->dispatch('https://hooks.example.com/events', 'secret', 'shop.order.paid', ['order_id' => 42]);
        self::assertSame(204, $result['status']);
        self::assertSame(32, strlen($result['delivery_id']));
    }

    public function testRejectsPrivateAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WebhookUrlValidator())->validate('https://127.0.0.1/internal');
    }
}
