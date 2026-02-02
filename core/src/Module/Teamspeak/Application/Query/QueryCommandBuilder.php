<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

final class QueryCommandBuilder
{
    public function __construct(
        private readonly ?QueryCommandValidator $validator = null,
    ) {
    }

    /**
     * @param array<int|string, scalar|null> $args
     */
    public function build(string $command, array $args = []): QueryCommand
    {
        $normalized = trim($command);
        if ($normalized === '' || !preg_match('/^[a-z0-9_]+$/i', $normalized)) {
            throw new QueryCommandException(sprintf('Invalid query command: %s', $command));
        }

        if ($this->validator !== null) {
            $this->validator->assertAllowed($normalized);
        }

        foreach ($args as $key => $value) {
            if (!is_int($key) && !is_string($key)) {
                throw new QueryCommandException('Invalid query command key.');
            }
            if (!is_scalar($value) && $value !== null) {
                throw new QueryCommandException(sprintf('Invalid query command value for %s.', (string) $key));
            }
        }

        return new QueryCommand($normalized, $args);
    }
}
