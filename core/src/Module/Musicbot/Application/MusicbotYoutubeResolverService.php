<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

final class MusicbotYoutubeResolverService
{
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
