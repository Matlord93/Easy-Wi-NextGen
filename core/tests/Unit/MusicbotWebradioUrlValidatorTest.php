<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\MusicbotWebradioUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MusicbotWebradioUrlValidatorTest extends TestCase
{
    private MusicbotWebradioUrlValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new MusicbotWebradioUrlValidator();
    }

    #[DataProvider('validUrlProvider')]
    public function testAcceptsValidUrls(string $url): void
    {
        $this->validator->validate($url);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function validUrlProvider(): iterable
    {
        yield 'plain http stream' => ['http://stream.example.com/live.mp3'];
        yield 'https stream' => ['https://stream.example.com/radio'];
        yield 'http with port' => ['http://cdn.example.com:8000/stream'];
        yield 'https with path' => ['https://icecast.example.net/stream/128kbps'];
        yield 'public ip' => ['http://203.0.113.5:8080/live'];
    }

    #[DataProvider('blockedUrlProvider')]
    public function testBlocksUnsafeUrls(string $url, string $expectFragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectFragment, '/') . '/i');
        $this->validator->validate($url);
    }

    /** @return iterable<string, array{string, string}> */
    public static function blockedUrlProvider(): iterable
    {
        yield 'empty url' => ['', 'must not be empty'];
        yield 'file scheme' => ['file:///etc/passwd', 'not allowed'];
        yield 'ftp scheme' => ['ftp://stream.example.com/audio', 'not allowed'];
        yield 'javascript scheme' => ['javascript:alert(1)', 'not allowed'];
        yield 'localhost hostname' => ['http://localhost/stream', 'localhost'];
        yield 'localhost hostname with port' => ['http://localhost:8080/live', 'localhost'];
        yield '127.x loopback' => ['http://127.0.0.1/stream', 'private or reserved'];
        yield '127.x loopback with port' => ['http://127.1.2.3:9000/radio', 'private or reserved'];
        yield 'private 192.168.x' => ['http://192.168.0.1/live.mp3', 'private or reserved'];
        yield 'private 10.x' => ['http://10.0.0.1/radio', 'private or reserved'];
        yield 'private 172.16.x' => ['http://172.16.5.1/stream', 'private or reserved'];
        yield 'private 172.31.x' => ['http://172.31.255.255/stream', 'private or reserved'];
        yield 'link-local 169.254.x' => ['http://169.254.1.1/meta', 'private or reserved'];
        yield 'ipv6 loopback' => ['http://[::1]/stream', 'loopback'];
        yield 'no host' => ['http:///nohost', 'not a valid URL'];
        yield 'too long' => ['https://example.com/' . str_repeat('a', 2048), 'too long'];
    }

    public function testAcceptsPortInPublicUrl(): void
    {
        $this->validator->validate('http://203.0.113.10:8000/stream.ogg');
        $this->addToAssertionCount(1);
    }

    public function testRejectsPrivate172Range(): void
    {
        foreach (range(16, 31) as $octet) {
            $this->expectException(\InvalidArgumentException::class);
            $this->validator->validate(sprintf('http://172.%d.0.1/stream', $octet));
            // Re-instantiate to clear the expected exception after each call.
            $this->validator = new MusicbotWebradioUrlValidator();
        }
        $this->addToAssertionCount(1);
    }
}
