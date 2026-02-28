<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Console;

interface ConsoleAgentGrpcClientInterface
{
    public function sendCommand(ConsoleCommandRequest $request): ConsoleCommandResult;

    /**
     * @return iterable<array<string,mixed>>
     */
    public function attachStream(int $instanceId): iterable;
}
