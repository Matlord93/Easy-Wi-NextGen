<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Console;

final readonly class ConsoleCommandResult
{
    public function __construct(
        public bool $applied,
        public bool $duplicate,
        public ?int $seq = null,
    ) {
    }
}
