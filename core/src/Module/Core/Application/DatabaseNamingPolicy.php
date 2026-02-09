<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class DatabaseNamingPolicy
{
    private const NAME_REGEX = '/^[a-zA-Z][a-zA-Z0-9_]{2,62}$/';

    /**
     * @return string[]
     */
    public function validateDatabaseName(string $name): array
    {
        if (!preg_match(self::NAME_REGEX, $name)) {
            return ['Database name must start with a letter and contain only letters, numbers, and underscores (3-63 chars).'];
        }

        return [];
    }

    /**
     * @return string[]
     */
    public function validateUsername(string $username): array
    {
        if (!preg_match(self::NAME_REGEX, $username)) {
            return ['Username must start with a letter and contain only letters, numbers, and underscores (3-63 chars).'];
        }

        return [];
    }
}
