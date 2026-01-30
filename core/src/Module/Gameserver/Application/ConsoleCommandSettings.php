<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

interface ConsoleCommandSettings
{
    /**
     * @return string[]
     */
    public function getCustomerConsoleAllowedCommands(): array;
}
