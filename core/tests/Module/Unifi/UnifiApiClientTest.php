<?php

declare(strict_types=1);

namespace App\Tests\Module\Unifi;

use App\Module\Unifi\Application\UnifiApiClient;
use App\Module\Unifi\Domain\Entity\UnifiSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UnifiApiClientTest extends TestCase
{
    public function testLoginCookiesAreForwarded(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            if (str_contains($url, '/api/auth/login')) {
                return new MockResponse('{}', [
                    'http_code' => 200,
                    'response_headers' => ['set-cookie' => ['TOKEN=abc; Path=/;']],
                ]);
            }

            return new MockResponse(json_encode(['data' => []]), [
                'http_code' => 200,
            ]);
        });

        $settings = new UnifiSettings();
        $settings->setBaseUrl('https://unifi.local');
        $settings->setUsername('admin');
        $settings->setVerifyTls(true);
        $settings->setSite('default');

        $apiClient = new UnifiApiClient($client);
        $apiClient->listPortForwardRules($settings, 'secret');

        self::assertCount(2, $requests);
        $listRequest = $requests[1];
        $headers = $listRequest[2]['headers'] ?? [];
        $cookieHeader = null;
        if (is_array($headers)) {
            $cookieHeader = $headers['Cookie'] ?? $headers['cookie'] ?? null;
            if ($cookieHeader === null) {
                foreach ($headers as $headerValue) {
                    if (is_string($headerValue) && str_starts_with(strtolower($headerValue), 'cookie:')) {
                        $cookieHeader = trim(substr($headerValue, strlen('cookie:')));
                        break;
                    }
                }
            }
        }

        self::assertSame('TOKEN=abc', $cookieHeader);
    }
}
