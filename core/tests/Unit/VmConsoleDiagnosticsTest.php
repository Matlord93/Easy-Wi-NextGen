<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Nodes\Application\Console\VmConsoleDiagnostics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class VmConsoleDiagnosticsTest extends TestCase
{
    public function testMissingSecretDoesNotAttemptAnyNetworkRequest(): void
    {
        $client = new MockHttpClient(static function (): never { throw new \LogicException('Unexpected request.'); });
        self::assertSame(['state' => 'missing', 'origin' => null], (new VmConsoleDiagnostics($client))->status());
    }

    #[DataProvider('statuses')]
    public function testHealthResponsesProduceOnlySafeStatusInformation(int $code, string $body, string $expected, ?string $origin): void
    {
        $secret = str_repeat('s', 64);
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($code, $body, $secret): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('http://127.0.0.1:7457/vm-console/health', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertContains('X-EasyWI-Console-Gateway: '.$secret, $options['headers']);

            return new MockResponse($body, ['http_code' => $code]);
        });
        $status = (new VmConsoleDiagnostics($client, $secret))->status();
        self::assertSame(['state' => $expected, 'origin' => $origin], $status);
        self::assertStringNotContainsString($secret, json_encode($status, JSON_THROW_ON_ERROR));
    }

    public static function statuses(): iterable
    {
        yield 'ready' => [200, '{"status":"ok","protocol_version":1,"public_origin":"https://panel.test:8443"}', 'ready', 'https://panel.test:8443'];
        yield 'mismatched secret' => [403, 'private details', 'secret_mismatch', null];
        yield 'old gateway' => [404, 'missing', 'update_gateway', null];
        yield 'no redirects' => [302, 'private redirect', 'unreachable', null];
        yield 'malformed JSON' => [200, '<html>login</html>', 'unreachable', null];
        yield 'wrong protocol' => [200, '{"status":"ok","protocol_version":999}', 'update_gateway', null];
        yield 'credentialed origin' => [200, '{"status":"ok","protocol_version":1,"public_origin":"https://user:secret@host.test"}', 'update_gateway', null];
        yield 'oversized body' => [200, str_repeat('x', 4097), 'unreachable', null];
    }
}
