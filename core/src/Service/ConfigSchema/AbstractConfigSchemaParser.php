<?php

declare(strict_types=1);

namespace App\Service\ConfigSchema;

abstract class AbstractConfigSchemaParser implements ConfigSchemaParserInterface
{
    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $parsed
     * @param string[] $warnings
     */
    protected function buildResult(array $schema, array $parsed, array $warnings, bool $sectioned): ConfigSchemaParseResult
    {
        $fields = $schema['fields'] ?? [];
        $values = [];
        $knownKeys = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $id = trim((string) ($field['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $key = trim((string) ($field['key'] ?? $id));
            $section = $sectioned ? trim((string) ($field['section'] ?? '')) : '';
            $rawValue = $sectioned
                ? (($parsed[$section] ?? [])[$key] ?? null)
                : ($parsed[$key] ?? null);
            $missing = $rawValue === null || $rawValue === '';
            if ($missing && array_key_exists('default', $field)) {
                $rawValue = $field['default'];
                $warnings[] = sprintf('Missing %s%s; using default.', $section !== '' ? $section . '.' : '', $key);
            } elseif ($missing) {
                $warnings[] = sprintf('Missing %s%s in config.', $section !== '' ? $section . '.' : '', $key);
            }
            $values[$id] = $this->castValue($rawValue, (string) ($field['type'] ?? 'string'));
            if ($rawValue !== null && $rawValue !== '') {
                $knownKeys[$this->normalizeKey($section, $key)] = true;
            }
        }

        if ($sectioned) {
            foreach ($parsed as $section => $entries) {
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $key => $value) {
                    if (!isset($knownKeys[$this->normalizeKey((string) $section, (string) $key)])) {
                        $warnings[] = sprintf('Unknown setting: %s%s', $section !== '' ? $section . '.' : '', $key);
                    }
                }
            }
        } else {
            foreach ($parsed as $key => $value) {
                if (!isset($knownKeys[$this->normalizeKey('', (string) $key)])) {
                    $warnings[] = sprintf('Unknown setting: %s', (string) $key);
                }
            }
        }

        return new ConfigSchemaParseResult($values, $warnings);
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
