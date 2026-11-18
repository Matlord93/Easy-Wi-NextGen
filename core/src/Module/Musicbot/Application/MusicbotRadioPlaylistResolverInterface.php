<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

interface MusicbotRadioPlaylistResolverInterface
{
    /**
     * Given a URL (direct stream or playlist file), return a resolved direct stream URL.
     * Throws \RuntimeException on failure.
     */
    public function resolve(string $url): string;

    /**
     * Returns resolved URL and detected content-type / metadata from stream headers.
     *
     * @return array{url: string, content_type: string|null, bitrate: int|null, stream_name: string|null,
     * genre: string|null}
     */
    public function resolveWithMetadata(string $url): array;
}
