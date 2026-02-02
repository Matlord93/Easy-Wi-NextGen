<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

final class QueryRequest
{
    /**
     * @param QueryCommand[] $commands
     */
    public function __construct(
        private readonly array $commands,
    ) {
    }

    /**
     * @return QueryCommand[]
     */
    public function commands(): array
    {
        return $this->commands;
    }
}
