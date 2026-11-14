<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

/**
 * Provides placeholder status information for the first Musicbot module shell.
 *
 * The module intentionally contains no bot, connector or audio runtime logic yet.
 */
final class MusicbotStatusProvider
{
    /**
     * @return array{module: string, status: string, instances_total: int, instances_running: int,
     * connectors: array{teamspeak: string, discord: string}, runtime: array{backend: string,
     * audio_logic_enabled: bool}}
     */
    public function getOverview(): array
    {
        return [
            'module' => 'musicbot',
            'status' => 'planned',
            'instances_total' => 0,
            'instances_running' => 0,
            'connectors' => [
                'teamspeak' => 'planned',
                'discord' => 'planned',
            ],
            'runtime' => [
                'backend' => 'native',
                'audio_logic_enabled' => false,
            ],
        ];
    }
}
