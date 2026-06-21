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
     * @return array<string, mixed>
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
