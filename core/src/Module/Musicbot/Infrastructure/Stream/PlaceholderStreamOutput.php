<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Infrastructure\Stream;

use App\Module\Musicbot\Domain\Entity\MusicbotStreamSettings;

/**
 * Placeholder implementation of StreamOutputInterface.
 *
 * This class exists to satisfy the dependency graph while no real Icecast/Shoutcast
 * backend is integrated. It never claims to be available, never starts an actual
 * stream, and returns a clear "not_available" status on every call.
 *
 * Replace this with a concrete implementation (e.g. IcecastStreamOutput) once a
 * real streaming backend is added to the infrastructure.
 */
final class PlaceholderStreamOutput implements StreamOutputInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function start(MusicbotStreamSettings $settings): array
    {
        return [
            'started' => false,
            'message' => 'No stream backend is configured. Real streaming is not yet available.',
            'mount_path' => null,
        ];
    }

    public function stop(MusicbotStreamSettings $settings): array
    {
        return [
            'stopped' => false,
            'message' => 'No stream backend is configured.',
        ];
    }

    public function getStatus(MusicbotStreamSettings $settings): array
    {
        return [
            'stream_ready' => false,
            'backend' => 'placeholder',
            'message' => 'Streaming support is prepared but not yet active. No audio is being broadcast.',
        ];
    }
}
