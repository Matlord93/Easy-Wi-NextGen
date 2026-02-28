<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Console;

interface AgentEndpointProbeInterface
{
    public function hasAnyEndpoint(): bool;
}
