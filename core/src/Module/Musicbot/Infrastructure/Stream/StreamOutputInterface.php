<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Infrastructure\Stream;

use App\Module\Musicbot\Domain\Entity\MusicbotStreamSettings;

/**
 * Abstraction for stream output backends (Icecast, Shoutcast, etc.).
 * Implementations are responsible for establishing and tearing down the
 * actual audio stream. The placeholder implementation does not stream anything.
 */
interface StreamOutputInterface
{
    /**
     * Returns true when this backend is capable of handling real streaming.
     */
    public function isAvailable(): bool;

    /**
     * Attempt to start the stream for the given settings.
     * Returns a status array with at minimum:
     *   - 'started': bool
     *   - 'message': string
     *   - 'mount_path': string|null
     *
     * @return array<string, mixed>
     */
    public function start(MusicbotStreamSettings $settings): array;

    /**
     * Attempt to stop the stream for the given settings.
     *
     * @return array<string, mixed>
     */
    public function stop(MusicbotStreamSettings $settings): array;

    /**
     * Returns current runtime status.
     *
     * @return array<string, mixed>
     */
    public function getStatus(MusicbotStreamSettings $settings): array;
}
