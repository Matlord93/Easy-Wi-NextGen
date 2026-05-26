<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Update;

interface TeamspeakUpdateProviderInterface
{
    public function supports(string $serverType): bool;

    public function checkForUpdates(string $installedVersion, string $os, string $arch, string $channel = 'stable'): UpdateResult;
}
