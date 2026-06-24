<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\MusicbotYoutubeResolverService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MusicbotYoutubeResolverTest extends TestCase
{
    private function buildResolver(string $binary = 'yt-dlp'): MusicbotYoutubeResolverService
    {
        return new MusicbotYoutubeResolverService(binary: $binary);
    }

    // ── URL validation ────────────────────────────────────────────────────────

    #[DataProvider('validYoutubeUrlProvider')]
    public function testValidatesKnownYoutubeDomains(string $url): void
    {
        $resolver = $this->buildResolver();
        $resolver->validateYoutubeUrl($url);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function validYoutubeUrlProvider(): iterable
    {
        yield 'youtube.com watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'youtu.be short' => ['https://youtu.be/dQw4w9WgXcQ'];
        yield 'music.youtube.com' => ['https://music.youtube.com/watch?v=abc123'];
        yield 'm.youtube.com' => ['https://m.youtube.com/watch?v=abc'];
        yield 'bare youtube.com' => ['https://youtube.com/watch?v=test'];
    }

    #[DataProvider('invalidYoutubeUrlProvider')]
    public function testRejectsNonYoutubeUrls(string $url, string $expectFragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectFragment, '/') . '/i');
        $this->buildResolver()->validateYoutubeUrl($url);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidYoutubeUrlProvider(): iterable
    {
        yield 'empty' => ['', 'must not be empty'];
        yield 'non-youtube domain' => ['https://evil.com/watch?v=abc', 'not a recognized YouTube domain'];
        yield 'vimeo' => ['https://vimeo.com/123456', 'not a recognized YouTube domain'];
        yield 'file scheme' => ['file:///etc/passwd', 'not a valid URL'];
        yield 'no scheme' => ['youtube.com/watch?v=abc', 'not a valid URL'];
        yield 'too long' => ['https://www.youtube.com/' . str_repeat('x', 2048), 'too long'];
    }

    // ── Binary availability ───────────────────────────────────────────────────

    public function testIsAvailableReturnsFalseForBogusPath(): void
    {
        $resolver = $this->buildResolver('/nonexistent/binary/yt-dlp-bogus');
        self::assertFalse($resolver->isAvailable());
    }

    // ── resolve() – mocked via fake binary ───────────────────────────────────

    public function testResolveReturnsAudioUrlFromYtDlpOutput(): void
    {
        // Write a tiny shell script that acts as a yt-dlp stub.
        $stubPath = tempnam(sys_get_temp_dir(), 'ytdlp_stub_') . '.sh';
        file_put_contents($stubPath, "#!/bin/sh\necho 'https://cdn.googlevideo.com/videoplayback?id=fakeid'\n");
        chmod($stubPath, 0o755);

        $resolver = $this->buildResolver($stubPath);
        $resolved = $resolver->resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        self::assertStringStartsWith('https://', $resolved);
        self::assertStringContainsString('googlevideo.com', $resolved);

        @unlink($stubPath);
    }

    public function testResolveThrowsWhenYtDlpExitsNonZero(): void
    {
        $stubPath = tempnam(sys_get_temp_dir(), 'ytdlp_fail_') . '.sh';
        file_put_contents($stubPath, "#!/bin/sh\nexit 1\n");
        chmod($stubPath, 0o755);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exited with code 1/');

        $resolver = $this->buildResolver($stubPath);
        $resolver->resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        @unlink($stubPath);
    }

    public function testResolveThrowsWhenOutputIsEmpty(): void
    {
        $stubPath = tempnam(sys_get_temp_dir(), 'ytdlp_empty_') . '.sh';
        file_put_contents($stubPath, "#!/bin/sh\necho ''\nexit 0\n");
        chmod($stubPath, 0o755);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no usable audio URL/');

        $resolver = $this->buildResolver($stubPath);
        $resolver->resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        @unlink($stubPath);
    }

    public function testResolveThrowsWhenOutputIsNotHttpUrl(): void
    {
        $stubPath = tempnam(sys_get_temp_dir(), 'ytdlp_bad_') . '.sh';
        file_put_contents($stubPath, "#!/bin/sh\necho 'not-a-url'\nexit 0\n");
        chmod($stubPath, 0o755);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no usable audio URL/');

        $resolver = $this->buildResolver($stubPath);
        $resolver->resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        @unlink($stubPath);
    }

    public function testResolveRejectsNonYoutubeUrlBeforeSpawning(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Uses 'true' binary – process would succeed but validation rejects URL first.
        $this->buildResolver('true')->resolve('https://evil.com/video');
    }
}
