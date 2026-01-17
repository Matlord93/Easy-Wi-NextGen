<?php

declare(strict_types=1);

namespace App\Module\Core\Application\ConfigSchema;

final class KeyValueConfigSchemaParser extends AbstractConfigSchemaParser
{
    public function format(): string
    {
        return 'key_value';
    }

    public function parse(string $content, array $schema): ConfigSchemaParseResult
    {
        $parsed = [];
        $warnings = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '' || str_starts_with($trimmed, ';') || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (!str_contains($trimmed, '=')) {
                $warnings[] = sprintf('Unrecognized line: %s', $trimmed);
                continue;
            }
            [$key, $value] = explode('=', $trimmed, 2);
            $key = trim($key);
            if ($key === '') {
                $warnings[] = sprintf('Unrecognized line: %s', $trimmed);
                continue;
            }
            $parsed[$key] = $this->unquoteValue($value);
        }

        return $this->buildResult($schema, $parsed, $warnings, false);
    }

    public function generate(array $schema, array $values): string
    {
        $fields = $schema['fields'] ?? [];
        $lines = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string) ($field['key'] ?? $field['id'] ?? ''));
            if ($key === '') {
                continue;
            }
            $rawValue = $this->valueForField($field, $values);
            $value = $this->stringifyValue($rawValue, (string) ($field['type'] ?? 'string'));
            $lines[] = sprintf('%s=%s', $key, $value);
        }

        return implode("\n", $lines) . "\n";
    }
}
