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
            '--get-url',
            '--format', 'bestaudio/best',
            '--no-playlist',
            '--no-warnings',
            '--quiet',
            // No cookies, no authentication — intentionally.
            $youtubeUrl,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('youtube_resolver: failed to start yt-dlp process.');
        }

        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $deadline = time() + self::RESOLVE_TIMEOUT_SECONDS;

        while (!feof($pipes[1]) || !feof($pipes[2])) {
            if (time() > $deadline) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new \RuntimeException('youtube_resolver: yt-dlp timed out after ' . self::RESOLVE_TIMEOUT_SECONDS . 's.');
            }
            $chunk = fread($pipes[1], 8192);
            if ($chunk !== false) {
                $stdout .= $chunk;
            }
            $errChunk = fread($pipes[2], 2048);
            if ($errChunk !== false) {
                $stderr .= $errChunk;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

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
        $result = shell_exec(sprintf('%s --version 2>/dev/null', escapeshellcmd($this->binary)));

        return $result !== null && trim((string) $result) !== '';
    }
}
