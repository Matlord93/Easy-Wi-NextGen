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
     * @param array<array-key, mixed> $args
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

        $normalizedArgs = [];
        foreach (array_keys($args) as $key) {
            $normalizedArgs[$key] = self::argument($args[$key], $key);
        }

        return new QueryCommand($normalized, $normalizedArgs);
    }
    private static function argument(mixed $value, int|string $key): null|bool|int|float|string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new QueryCommandException(sprintf('Invalid query command value for %s.', (string) $key));
        }
        return $value;
    }
}
