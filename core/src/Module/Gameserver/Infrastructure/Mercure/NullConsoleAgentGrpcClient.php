<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Mercure;

use App\Module\Gameserver\Application\Console\ConsoleAgentGrpcClientInterface;
use App\Module\Gameserver\Application\Console\ConsoleCommandRequest;
use App\Module\Gameserver\Application\Console\ConsoleCommandResult;

final class NullConsoleAgentGrpcClient implements ConsoleAgentGrpcClientInterface
{
    public function sendCommand(ConsoleCommandRequest $request): ConsoleCommandResult
    {
        return new ConsoleCommandResult(true, false, null);
    }

    public function attachStream(int $instanceId): iterable
    {
        return [];
    }
}
