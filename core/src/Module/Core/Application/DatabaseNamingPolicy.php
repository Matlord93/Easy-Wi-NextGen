<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class DatabaseNamingPolicy
{
    private const NAME_REGEX = '/^[a-zA-Z][a-zA-Z0-9_]{2,62}$/';
    private const QUOTED_IDENTIFIER_REGEX = '/[`"\[\]]/';
    private const RESERVED_WORDS = [
        'select', 'insert', 'update', 'delete', 'drop', 'create', 'alter', 'grant', 'revoke',
        'database', 'schema', 'table', 'user', 'role', 'postgres', 'mysql',
    ];

    /**
     * @return string[]
     */
    public function validateDatabaseName(string $name): array
    {
        $normalized = $this->normalizeIdentifier($name);

        if ($this->containsQuotedIdentifierSyntax($name)) {
            return ['Quoted identifiers are not allowed. Use plain unquoted names.'];
        }

        if (!preg_match(self::NAME_REGEX, $normalized)) {
            return ['Database name must start with a letter and contain only letters, numbers, and underscores (3-63 chars).'];
        }
        if (in_array($normalized, self::RESERVED_WORDS, true)) {
            return ['Database name is reserved and cannot be used.'];
        }

        return [];
    }

    /**
     * @return string[]
     */
    public function validateUsername(string $username): array
    {
        $normalized = $this->normalizeIdentifier($username);

        if ($this->containsQuotedIdentifierSyntax($username)) {
            return ['Quoted identifiers are not allowed. Use plain unquoted names.'];
        }

        if (!preg_match(self::NAME_REGEX, $normalized)) {
            return ['Username must start with a letter and contain only letters, numbers, and underscores (3-63 chars).'];
        }
        if (in_array($normalized, self::RESERVED_WORDS, true)) {
            return ['Username is reserved and cannot be used.'];
        }

        return [];
    }

    private function containsQuotedIdentifierSyntax(string $identifier): bool
    {
        return preg_match(self::QUOTED_IDENTIFIER_REGEX, $identifier) === 1;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        $normalized = trim($identifier);
        $normalized = trim($normalized, "`\"[] ");

        return strtolower($normalized);
    }
}
