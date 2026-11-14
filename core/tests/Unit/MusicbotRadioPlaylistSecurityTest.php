<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\MusicbotRadioPlaylistResolver;
use App\Module\Musicbot\Application\MusicbotWebradioUrlValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MusicbotRadioPlaylistSecurityTest extends TestCase
{
    public function testPlaylistIsResolvedOverPublicHttp(): void
    {
        $client = new MockHttpClient(new MockResponse("#EXTM3U\nhttps://radio.example.test/live\n"));
        $resolver = new MusicbotRadioPlaylistResolver(new MusicbotWebradioUrlValidator(), $client);

        self::assertSame('https://radio.example.test/live', $resolver->resolve('https://93.184.216.34/list.m3u'));
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testRedirectToPrivateNetworkIsRejectedBeforeSecondRequest(): void
    {
        $client = new MockHttpClient(new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['Location: http://127.0.0.1/private.m3u'],
            'redirect_url' => 'http://127.0.0.1/private.m3u',
        ]));
        $resolver = new MusicbotRadioPlaylistResolver(new MusicbotWebradioUrlValidator(), $client);

        try {
            $resolver->resolve('https://93.184.216.34/list.m3u');
            self::fail('Redirect into the private network was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Playlist HTTP request failed.', $exception->getMessage());
            self::assertSame(1, $client->getRequestsCount());
        }
    }

    public function testPrivateIpv6AddressIsBlockedBeforeAnyRequest(): void
    {
        $client = new MockHttpClient(new MockResponse('unexpected'));
        $resolver = new MusicbotRadioPlaylistResolver(new MusicbotWebradioUrlValidator(), $client);

        try {
            $resolver->resolve('http://[fd00::1]/list.m3u');
            self::fail('Private IPv6 address was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('private or reserved', $exception->getMessage());
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testOversizedPlaylistIsRejected(): void
    {
        $client = new MockHttpClient(new MockResponse(str_repeat('x', 2_097_153)));
        $resolver = new MusicbotRadioPlaylistResolver(new MusicbotWebradioUrlValidator(), $client);

        $this->expectExceptionMessage('Playlist file is too large.');
        $resolver->resolve('https://93.184.216.34/list.m3u');
    }

    public function testStreamMetadataUsesABoundedRangeRequest(): void
    {
        $requestOptions = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requestOptions): MockResponse {
            $requestOptions = $options;

            return new MockResponse('audio', [
                'response_headers' => ['Content-Type: audio/mpeg', 'icy-br: 128', 'icy-name: Test station'],
            ]);
        });
        $resolver = new MusicbotRadioPlaylistResolver(new MusicbotWebradioUrlValidator(), $client);
        $metadata = $resolver->resolveWithMetadata('https://93.184.216.34/live');

        self::assertSame('audio/mpeg', $metadata['content_type']);
        self::assertSame(128, $metadata['bitrate']);
        self::assertSame('Test station', $metadata['stream_name']);
        self::assertContains('Range: bytes=0-0', $requestOptions['headers']);
        self::assertFalse($requestOptions['buffer']);
    }
}
