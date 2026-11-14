<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\MusicbotRadioPlaylistResolver;
use App\Module\Musicbot\Application\MusicbotWebradioUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class MusicbotRadioPlaylistUpstreamSecurityTest extends TestCase
{
    public function testPublicPlaylistRemainsUsable(): void
    {
        $response = new MockResponse("#EXTM3U\nhttps://radio.example.test/live.mp3\n", ['primary_ip' => '8.8.8.8']);
        $resolver = new MusicbotRadioPlaylistResolver(new MusicbotWebradioUrlValidator(), new MockHttpClient($response));
        self::assertSame('https://radio.example.test/live.mp3', $resolver->resolve('https://8.8.8.8/list.m3u'));
    }

    #[DataProvider('unsafeRedirects')]
    public function testRedirectCannotAccessPrivateNetworksOrPhpWrappers(string $target): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls, $target): MockResponse {
            ++$calls;
            return new MockResponse('', ['http_code' => 302, 'primary_ip' => '8.8.8.8', 'redirect_url' => $target, 'response_headers' => ['Location: '.$target]]);
        });
        $resolver = new MusicbotRadioPlaylistResolver(new MusicbotWebradioUrlValidator(), $client);
        try {
            $resolver->resolve('https://8.8.8.8/list.m3u');
            self::fail('Unsafe redirect must fail.');
        } catch (\RuntimeException $error) {
            self::assertInstanceOf(\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface::class, $error->getPrevious());
            self::assertSame(1, $calls, $error->getMessage());
        }
    }

    public static function unsafeRedirects(): iterable
    {
        yield ['http://127.0.0.1/admin'];
        yield ['http://169.254.169.254/latest/meta-data/'];
        yield ['http://[::1]/admin'];
        yield ['http://[fd00::1]/admin'];
        yield ['http://2130706433/admin'];
        yield ['http://224.0.0.1/admin'];
        yield ['http://[ff02::1]/admin'];
        yield ['file:///etc/passwd'];
    }

    public function testUnboundedPlaylistBodyIsCanceled(): void
    {
        $response = new MockResponse((static function (): \Generator {
            for ($i = 0; $i < 100; ++$i) {
                yield str_repeat('x', 65536);
            }
        })(), ['primary_ip' => '8.8.8.8']);
        $client = $this->trackingClient($response);
        $resolver = new MusicbotRadioPlaylistResolver(new MusicbotWebradioUrlValidator(), $client);
        try {
            $resolver->resolve('https://8.8.8.8/list.m3u');
            self::fail('Oversize playlist must fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('1 MiB', $error->getMessage());
            self::assertTrue($client->sent[0]->getInfo('canceled'));
        }
    }

    public function testMetadataProbeUsesUnbufferedRequestAndCancelsTheLiveStream(): void
    {
        $reads = 0;
        $response = new MockResponse((static function () use (&$reads): \Generator {
            ++$reads;
            yield str_repeat('x', 65536);
        })(), ['primary_ip' => '8.8.8.8', 'response_headers' => ['content-type: audio/mpeg', 'icy-br: 128', 'icy-name: Radio']]);
        $client = $this->trackingClient($response);
        $resolver = new MusicbotRadioPlaylistResolver(new MusicbotWebradioUrlValidator(), $client);
        $metadata = $resolver->resolveWithMetadata('https://8.8.8.8/live');
        self::assertSame('audio/mpeg', $metadata['content_type']);
        self::assertSame(128, $metadata['bitrate']);
        self::assertFalse($client->options[0]['buffer']);
        self::assertTrue($client->sent[0]->getInfo('canceled'));
    }

    public function testXspfCannotLoadExternalEntities(): void
    {
        $resolver = new MusicbotRadioPlaylistResolver(new MusicbotWebradioUrlValidator());
        $method = new \ReflectionMethod($resolver, 'parseXspf');
        $xml = '<!DOCTYPE playlist [<!ENTITY x SYSTEM "file:///etc/passwd">]><playlist><location>&x;</location></playlist>';
        self::assertSame([], $method->invoke($resolver, $xml));
    }

    private function trackingClient(MockResponse $response): MockHttpClient
    {
        return new class ($response) extends MockHttpClient {
            public array $sent = [];
            public array $options = [];

            #[\Override]
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $response = parent::request($method, $url, $options);
                $this->sent[] = $response;
                $this->options[] = $options;

                return $response;
            }
        };
    }
}
