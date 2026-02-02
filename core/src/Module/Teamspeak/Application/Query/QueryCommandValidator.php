<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

final class QueryCommandValidator
{
    /**
     * @param array<string, bool> $allowedCommands
     */
    public function __construct(
        private readonly array $allowedCommands,
    ) {
    }

    public function assertAllowed(string $command): void
    {
        $normalized = strtolower($command);
        if (!isset($this->allowedCommands[$normalized])) {
            throw new QueryCommandException(sprintf('Command not allowed: %s', $command));
        }
    }
}
