<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Console;

interface ConsoleCommandLimiterInterface
{
    public function consume(string $key): bool;
}
