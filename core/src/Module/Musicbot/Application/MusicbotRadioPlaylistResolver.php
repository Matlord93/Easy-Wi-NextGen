<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves playlist file formats (M3U, PLS, XSPF) to direct stream URLs.
 * Follows HTTP redirects and selects the first reachable stream URL.
 */
class MusicbotRadioPlaylistResolver
{
    private const int MAX_REDIRECTS = 5;
    private const int CONNECT_TIMEOUT = 8;
    private const int TIMEOUT = 12;
    private const int MAX_PLAYLIST_BYTES = 1_048_576;

    private readonly HttpClientInterface $publicClient;

    public function __construct(
        private readonly MusicbotWebradioUrlValidator $urlValidator,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->publicClient = new NoPrivateNetworkHttpClient($httpClient ?? HttpClient::create(), MusicbotWebradioUrlValidator::BLOCKED_NETWORKS);
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
     * @return array{url: string, content_type: string|null, bitrate: int|null, stream_name: string|null,
     * genre: string|null}
     */
    public function resolveWithMetadata(string $url): array
    {
        $resolved = $this->resolve($url);
        $meta = $this->probeStreamHeaders($resolved);

        return array_merge(['url' => $resolved], $meta);
    }

    private function detectFormat(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? strtolower($path) : '';

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
        $response = null;
        try {
            $response = $this->publicClient->request('GET', $url, [
                'max_redirects' => self::MAX_REDIRECTS,
                'timeout' => self::CONNECT_TIMEOUT,
                'max_duration' => self::TIMEOUT,
                'buffer' => false,
                'headers' => ['User-Agent' => 'EasyWI-Musicbot/1.0 (playlist-resolver)'],
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf('Playlist URL returned HTTP %d.', $status));
            }

            $body = '';
            foreach ($this->publicClient->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new \RuntimeException('Playlist request timed out.');
                }
                $body .= $chunk->getContent();
                if (strlen($body) > self::MAX_PLAYLIST_BYTES) {
                    throw new \RuntimeException('Playlist file is too large. (1 MiB limit)');
                }
            }
            if ($body === '') {
                throw new \RuntimeException('Playlist file was empty.');
            }

            return $body;
        } catch (TransportExceptionInterface $exception) {
            throw new \RuntimeException('Playlist HTTP request failed.', 0, $exception);
        } finally {
            $response?->cancel();
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
        if (str_contains(strtoupper($content), '<!DOCTYPE')) {
            return [];
        }

        $urls = [];
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $xml = new \SimpleXMLElement($content, LIBXML_NONET);
            $xml->registerXPathNamespace('xspf', 'http://xspf.org/ns/0/');
            $locations = $xml->xpath('//xspf:location');
            if (!is_array($locations) || $locations === []) {
                $locations = $xml->xpath('//location');
            }
            if (!is_array($locations)) {
                return [];
            }
            foreach ($locations as $loc) {
                $candidate = trim((string) $loc);
                if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) {
                    $urls[] = $candidate;
                }
            }
        } catch (\Exception) {
            // An invalid playlist has no usable stream locations.
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return array{content_type: string|null, bitrate: int|null, stream_name: string|null, genre: string|null}
     */
    private function probeStreamHeaders(string $url): array
    {
        $response = null;
        try {
            $response = $this->publicClient->request('GET', $url, [
                'max_redirects' => self::MAX_REDIRECTS,
                'timeout' => 5,
                'max_duration' => 5,
                'buffer' => false,
                'headers' => [
                    'User-Agent' => 'EasyWI-Musicbot/1.0 (stream-probe)',
                    'Icy-MetaData' => '1',
                    'Range' => 'bytes=0-0',
                ],
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return ['content_type' => null, 'bitrate' => null, 'stream_name' => null, 'genre' => null];
            }
            $headers = $response->getHeaders(false);
            $bitrate = $headers['icy-br'][0] ?? null;

            return [
                'content_type' => $headers['content-type'][0] ?? null,
                'bitrate' => $bitrate !== null && ctype_digit($bitrate) ? (int) $bitrate : null,
                'stream_name' => $headers['icy-name'][0] ?? null,
                'genre' => $headers['icy-genre'][0] ?? null,
            ];
        } catch (TransportExceptionInterface) {
            return ['content_type' => null, 'bitrate' => null, 'stream_name' => null, 'genre' => null];
        } finally {
            // Radio responses may never finish: metadata probing only needs the headers.
            $response?->cancel();
        }
    }
}
