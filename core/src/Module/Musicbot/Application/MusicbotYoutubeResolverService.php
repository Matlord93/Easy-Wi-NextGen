<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class MusicbotYoutubeResolverService
{
    private const RESOLVE_TIMEOUT_SECONDS = 30;
    private const YTDLP_BINARY = 'yt-dlp';

    // Only accept known YouTube domains to prevent SSRF via the resolver.
    private const ALLOWED_HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'youtu.be',
        'music.youtube.com',
    ];

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $binary = self::YTDLP_BINARY,
    ) {
    }

    /**
     * Validate that $url looks like a YouTube URL.
     * Does NOT perform network access.
     */
    public function validateYoutubeUrl(string $url): void
    {
        $url = trim($url);
        if ($url === '') {
            throw new \InvalidArgumentException('YouTube URL must not be empty.');
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new \InvalidArgumentException('YouTube URL is not a valid URL.');
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('YouTube URL must use http or https.');
        }

        $host = strtolower(trim($parsed['host'], '[]'));
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new \InvalidArgumentException(
                sprintf('URL host "%s" is not a recognized YouTube domain.', $host),
            );
        }

        if (strlen($url) > 2048) {
            throw new \InvalidArgumentException('YouTube URL is too long.');
        }
    }

    /**
     * Resolve a YouTube URL to a direct audio stream URL using yt-dlp.
     * Returns the resolved audio URL, or throws on failure.
     *
     * Callers should catch \RuntimeException and fall back gracefully.
     */
    public function resolve(string $youtubeUrl): string
    {
        $this->validateYoutubeUrl($youtubeUrl);

        $cmd = [
            $this->binary,
            '--ignore-config',
            '--get-url',
            '--format', 'bestaudio/best',
            '--no-playlist',
            '--no-warnings',
            '--quiet',
            // No cookies, no authentication — intentionally.
            '--',
            $youtubeUrl,
        ];

        $result = MusicbotProcessRunner::run($cmd, self::RESOLVE_TIMEOUT_SECONDS);
        $stdout = $result['stdout'];
        $exitCode = $result['exit_code'];

        if ($exitCode !== 0) {
            $this->logger->warning('youtube_resolver: yt-dlp exited with code {code}', [
                'code' => $exitCode,
                'youtube_url_host' => parse_url($youtubeUrl, PHP_URL_HOST),
            ]);
            throw new \RuntimeException(
                sprintf('youtube_resolver: yt-dlp exited with code %d. Check that yt-dlp is installed and up-to-date.', $exitCode),
            );
        }

        $resolved = trim(explode("\n", trim($stdout))[0]);
        if ($resolved === '' || !str_starts_with($resolved, 'http')) {
            throw new \RuntimeException('youtube_resolver: yt-dlp returned no usable audio URL.');
        }

        return $resolved;
    }

    public function isAvailable(): bool
    {
        try {
            $result = MusicbotProcessRunner::run([$this->binary, '--ignore-config', '--version'], 5, 65536);

            return 0 === $result['exit_code'] && '' !== trim($result['stdout']);
        } catch (\RuntimeException) {
            return false;
        }
    }
}
