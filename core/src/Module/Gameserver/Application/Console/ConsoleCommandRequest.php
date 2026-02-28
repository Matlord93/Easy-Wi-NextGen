<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Console;

final readonly class ConsoleCommandRequest
{
    public function __construct(
        public int $instanceId,
        public string $command,
        public string $idempotencyKey,
        public int $issuedAtUnixMs,
        public string $actorId,
    ) {
    }
}
