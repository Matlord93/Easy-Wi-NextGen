<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Provider;

final class Teamspeak3Adapter extends AbstractTeamspeakAdapter
{
    #[\Override]
    public function supports(string $providerType): bool
    {
        return $providerType === 'ts3';
    }
}
