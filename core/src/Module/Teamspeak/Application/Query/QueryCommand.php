<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

final class QueryCommand
{
    /**
     * @param array<int|string, scalar|null> $args
     */
    public function __construct(
        private readonly string $command,
        private readonly array $args = [],
    ) {
    }

    public function command(): string
    {
        return $this->command;
    }

    /**
     * @return array<int|string, scalar|null>
     */
    public function args(): array
    {
        return $this->args;
    }
}
