<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Update;

final class Teamspeak3UpdateProvider implements TeamspeakUpdateProviderInterface
{
    public function supports(string $serverType): bool
    {
        return $serverType === 'ts3';
    }

    public function checkForUpdates(string $installedVersion, string $os, string $arch, string $channel = 'stable'): UpdateResult
    {
        return new UpdateResult('ts3', $installedVersion, null, false, 'source_not_configured', 'teamspeak.update.source_not_configured');
    }
}
