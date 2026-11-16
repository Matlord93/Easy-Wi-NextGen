<?php

declare(strict_types=1);

namespace App\Module\Core\Application\ConfigSchema;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;

abstract class AbstractConfigSchemaParser implements ConfigSchemaParserInterface
{
    /**
     * @param array<string, mixed> $schema
     * @param array<array-key, mixed> $parsed
     * @param string[] $warnings
     */
    protected function buildResult(array $schema, array $parsed, array $warnings, bool $sectioned): ConfigSchemaParseResult
    {
        $fields = JsonArray::orEmpty($schema['fields'] ?? null);
        $values = [];
        $knownKeys = [];

        foreach (array_keys($fields) as $fieldIndex) {
            $field = JsonMap::fromValue($fields[$fieldIndex]);
            if ($field === null) {
                continue;
            }
            $id = trim((string) ($field['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $key = trim((string) ($field['key'] ?? $id));
            $section = $sectioned ? trim((string) ($field['section'] ?? '')) : '';
            $this->processField($values, $warnings, $knownKeys, $field, $id, $section, $key,
                $sectioned ? (JsonArray::orEmpty($parsed[$section] ?? null)[$key] ?? null) : ($parsed[$key] ?? null));
        }

        if ($sectioned) {
            foreach (array_keys($parsed) as $section) {
                $entries = JsonArray::fromValue($parsed[$section]);
                if ($entries === null) {
                    continue;
                }
                foreach (array_keys($entries) as $key) {
                    if (!isset($knownKeys[$this->normalizeKey((string) $section, (string) $key)])) {
                        $warnings[] = sprintf('Unknown setting: %s%s', $section !== '' ? $section . '.' : '', $key);
                    }
                }
            }
        } else {
            foreach (array_keys($parsed) as $key) {
                if (!isset($knownKeys[$this->normalizeKey('', (string) $key)])) {
                    $warnings[] = sprintf('Unknown setting: %s', $key);
                }
            }
        }

        return new ConfigSchemaParseResult($values, $warnings);
    }

    /**
     * @param array<string,mixed> $values
     * @param array<array-key,string> $warnings
     * @param array<string,bool> $knownKeys
     * @param array<string,mixed> $field
     */
    private function processField(
        array &$values,
        array &$warnings,
        array &$knownKeys,
        array $field,
        string $id,
        string $section,
        string $key,
        mixed $rawValue,
        bool $defaultUsed = false,
    ): void
    {
        $missing = $rawValue === null || $rawValue === '';
        if ($missing && !$defaultUsed) {
            if (array_key_exists('default', $field)) {
                $warnings[] = sprintf('Missing %s%s; using default.', $section !== '' ? $section.'.' : '', $key);
                $this->processField($values, $warnings, $knownKeys, $field, $id, $section, $key, $field['default'], true);
                return;
            }
            $warnings[] = sprintf('Missing %s%s in config.', $section !== '' ? $section.'.' : '', $key);
        }
        self::setField($values, $id, $this->castValue($rawValue, (string) ($field['type'] ?? 'string')));
        if (!$missing) {
            $knownKeys[$this->normalizeKey($section, $key)] = true;
        }
    }

    /**
     * @template T
     * @param array<string,T> $values
     * @param T $value
     */
    private static function setField(array &$values, string $key, mixed $value): void
    {
        $values[$key] = $value;
    }

    protected function castValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (strtolower($type)) {
            'bool', 'boolean' => $this->castBool($value),
            'int', 'integer' => is_numeric($value) ? (int) $value : (int) trim((string) $value),
            'float', 'double' => is_numeric($value) ? (float) $value : (float) trim((string) $value),
            default => is_scalar($value) ? (string) $value : $value,
        };
    }

    protected function stringifyValue(mixed $value, string $type): string
    {
        if ($value === null) {
            return '';
        }

        if (in_array(strtolower($type), ['bool', 'boolean'], true)) {
            return $this->castBool($value) ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $values
     */
    protected function valueForField(array $field, array $values): mixed
    {
        $id = (string) ($field['id'] ?? '');
        if ($id !== '' && array_key_exists($id, $values)) {
            return $values[$id];
        }

        return $field['default'] ?? null;
    }

    protected function normalizeKey(string $section, string $key): string
    {
        return $section !== '' ? $section . '.' . $key : $key;
    }

    protected function unquoteValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
            $inner = substr($value, 1, -1);
            if ($quote === '"') {
                $inner = str_replace(['\\"', '\\\\'], ['"', '\\'], $inner);
            }
            return $inner;
        }

        return $value;
    }

    private function castBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));
        if ($value === '' || $value === '0' || $value === 'false' || $value === 'no' || $value === 'off') {
            return false;
        }

        return true;
    }
}
