<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

/**
 * Resolves playlist file formats (M3U, PLS, XSPF) to direct stream URLs.
 * Follows HTTP redirects and selects the first reachable stream URL.
 */
final class MusicbotRadioPlaylistResolver
{
    private const MAX_REDIRECTS = 5;
    private const CONNECT_TIMEOUT = 8;
    private const TIMEOUT = 12;

    public function __construct(
        private readonly MusicbotWebradioUrlValidator $urlValidator,
    ) {
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
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialise HTTP client.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => self::MAX_REDIRECTS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => 'EasyWI-Musicbot/1.0 (playlist-resolver)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException(sprintf('HTTP request failed: %s', $error));
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException(sprintf('Playlist URL returned HTTP %d.', $httpCode));
        }

        if (!is_string($body) || $body === '') {
            throw new \RuntimeException('Playlist file was empty.');
        }

        return $body;
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
        try {
            $xml = new \SimpleXMLElement($content);
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
        $ch = curl_init($url);
        if ($ch === false) {
            return ['content_type' => null, 'bitrate' => null, 'stream_name' => null, 'genre' => null];
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => false,
            CURLOPT_RANGE          => '0-0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => self::MAX_REDIRECTS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_USERAGENT      => 'EasyWI-Musicbot/1.0 (stream-probe)',
            CURLOPT_HTTPHEADER     => ['Icy-MetaData: 1'],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$responseHeaders): int {
                $len = strlen($header);
                if (str_contains($header, ':')) {
                    [$name, $value] = explode(':', $header, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return $len;
            },
        ]);

        curl_exec($ch);
        curl_close($ch);

        return [
            'content_type' => $responseHeaders['content-type'] ?? null,
            'bitrate'      => isset($responseHeaders['icy-br']) ? (int) $responseHeaders['icy-br'] : null,
            'stream_name'  => $responseHeaders['icy-name'] ?? null,
            'genre'        => $responseHeaders['icy-genre'] ?? null,
        ];
    }
}
