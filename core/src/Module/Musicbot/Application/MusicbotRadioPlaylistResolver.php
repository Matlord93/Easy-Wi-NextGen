<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves playlist file formats (M3U, PLS, XSPF) to direct stream URLs.
 * Follows HTTP redirects and selects the first reachable stream URL.
 */
class MusicbotRadioPlaylistResolver
{
    private const MAX_REDIRECTS = 5;
    private const MAX_PLAYLIST_BYTES = 1048576;
    private readonly HttpClientInterface $http;
    private const TIMEOUT = 12;

    public function __construct(
        private readonly MusicbotWebradioUrlValidator $urlValidator,
        ?HttpClientInterface $httpClient = null,
    ) {
        // Validates and pins DNS before connecting, including every redirect.
        $this->http = new NoPrivateNetworkHttpClient($httpClient ?? HttpClient::create(), MusicbotWebradioUrlValidator::BLOCKED_NETWORKS);
    }

    /**
     * Given a URL (direct stream or playlist file), return a resolved direct stream URL.
     * Throws \RuntimeException on failure.
     */
    public function resolve(string $url): string
    {
        $url = trim($url);
        $this->urlValidator->validate($url);

        $detected = $this->detectFormat($url);

        if ($detected === 'direct') {
            return $url;
        }

        $content = $this->fetchContent($url);

        $candidates = match ($detected) {
            'm3u'  => $this->parseM3u($content),
            'pls'  => $this->parsePls($content),
            'xspf' => $this->parseXspf($content),
            default => throw new \RuntimeException(sprintf('Unsupported playlist format for URL: %s', $url)),
        };

        if ($candidates === []) {
            throw new \RuntimeException('Playlist file contained no stream URLs.');
        }

        foreach ($candidates as $candidate) {
            try {
                $this->urlValidator->validate($candidate);

                return $candidate;
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        throw new \RuntimeException('No valid stream URL found in playlist file.');
    }

    /**
     * Returns resolved URL and detected content-type / metadata from stream headers.
     *
     * @return array{url: string, content_type: string|null, bitrate: int|null, stream_name: string|null, genre: string|null}
     */
    public function resolveWithMetadata(string $url): array
    {
        $resolved = $this->resolve($url);
        $meta = $this->probeStreamHeaders($resolved);

        return array_merge(['url' => $resolved], $meta);
    }

    private function detectFormat(string $url): string
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');

        if (str_ends_with($path, '.m3u') || str_ends_with($path, '.m3u8')) {
            return 'm3u';
        }

        if (str_ends_with($path, '.pls')) {
            return 'pls';
        }

        if (str_ends_with($path, '.xspf')) {
            return 'xspf';
        }

        return 'direct';
    }

    private function fetchContent(string $url): string
    {
        $this->urlValidator->validate($url);
        $response = $this->http->request('GET', $url, [
            'max_redirects' => self::MAX_REDIRECTS,
            'timeout' => self::TIMEOUT,
            'max_duration' => self::TIMEOUT,
            'buffer' => false,
            'headers' => ['User-Agent' => 'EasyWI-Musicbot/1.0 (playlist-resolver)'],
            'on_progress' => static function (int $downloaded, int $size, array $info): void {
                if ($downloaded > self::MAX_PLAYLIST_BYTES && empty($info['canceled'])) {
                    throw new \RuntimeException('Playlist exceeds the 1 MiB limit.');
                }
            },
        ]);
        try {
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf('Playlist URL returned HTTP %d.', $status));
            }
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new \RuntimeException('Playlist request timed out.');
                }
                $body .= $chunk->getContent();
                if (strlen($body) > self::MAX_PLAYLIST_BYTES) {
                    throw new \RuntimeException('Playlist exceeds the 1 MiB limit.');
                }
            }
            if ('' === $body) {
                throw new \RuntimeException('Playlist file was empty.');
            }

            return $body;
        } finally {
            $response->cancel();
        }
    }

    /** @return string[] */
    private function parseM3u(string $content): array
    {
        $urls = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'http://') || str_starts_with($line, 'https://')) {
                $urls[] = $line;
            }
        }

        return array_values(array_unique($urls));
    }

    /** @return string[] */
    private function parsePls(string $content): array
    {
        $urls = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (preg_match('/^File\d+=(.+)/i', $line, $m)) {
                $candidate = trim($m[1]);
                if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) {
                    $urls[] = $candidate;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /** @return string[] */
    private function parseXspf(string $content): array
    {
        $urls = [];
        if (str_contains(strtoupper($content), '<!DOCTYPE')) {
            return [];
        }
        try {
            $xml = new \SimpleXMLElement($content, LIBXML_NONET);
            $xml->registerXPathNamespace('xspf', 'http://xspf.org/ns/0/');
            $locations = $xml->xpath('//xspf:location') ?: $xml->xpath('//location') ?: [];
            foreach ($locations as $loc) {
                $candidate = trim((string) $loc);
                if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) {
                    $urls[] = $candidate;
                }
            }
        } catch (\Exception) {
            // Invalid XML; fall through with empty result
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return array{content_type: string|null, bitrate: int|null, stream_name: string|null, genre: string|null}
     */
    private function probeStreamHeaders(string $url): array
    {
        $empty = ['content_type' => null, 'bitrate' => null, 'stream_name' => null, 'genre' => null];
        $response = null;
        try {
            $this->urlValidator->validate($url);
            $response = $this->http->request('GET', $url, [
                'max_redirects' => self::MAX_REDIRECTS,
                'timeout' => 5,
                'max_duration' => 5,
                'buffer' => false,
                'headers' => ['Range' => 'bytes=0-0', 'Icy-MetaData' => '1', 'User-Agent' => 'EasyWI-Musicbot/1.0 (stream-probe)'],
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return $empty;
            }
            $headers = $response->getHeaders(false);

            return [
                'content_type' => $headers['content-type'][0] ?? null,
                'bitrate' => isset($headers['icy-br'][0]) ? (int) $headers['icy-br'][0] : null,
                'stream_name' => $headers['icy-name'][0] ?? null,
                'genre' => $headers['icy-genre'][0] ?? null,
            ];
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface|\InvalidArgumentException) {
            return $empty;
        } finally {
            // A live stream can ignore Range; never wait for or buffer its body.
            $response?->cancel();
        }
    }
}
