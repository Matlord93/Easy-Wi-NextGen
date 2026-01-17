<?php

declare(strict_types=1);

namespace App\Module\Core\Application\ConfigSchema;

final class ConVarConfigSchemaParser extends AbstractConfigSchemaParser
{
    public function format(): string
    {
        return 'convar';
    }

    public function parse(string $content, array $schema): ConfigSchemaParseResult
    {
        $parsed = [];
        $warnings = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '' || str_starts_with($trimmed, ';') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
                continue;
            }

            if (!preg_match('/^(\S+)\s+(.*)$/', $trimmed, $matches)) {
                $warnings[] = sprintf('Unrecognized line: %s', $trimmed);
                continue;
            }

            $key = $matches[1];
            $value = $this->unquoteValue($matches[2]);
            $parsed[$key] = $value;
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
            if (preg_match('/\s/', $value) === 1) {
                $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
                $value = sprintf('"%s"', $escaped);
            }
            $lines[] = sprintf('%s %s', $key, $value);
        }

        return implode("\n", $lines) . "\n";
    }
}
