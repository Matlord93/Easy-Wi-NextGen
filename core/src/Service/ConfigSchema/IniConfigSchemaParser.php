<?php

declare(strict_types=1);

namespace App\Service\ConfigSchema;

final class IniConfigSchemaParser extends AbstractConfigSchemaParser
{
    public function format(): string
    {
        return 'ini';
    }

    public function parse(string $content, array $schema): ConfigSchemaParseResult
    {
        $parsed = [];
        $warnings = [];
        $currentSection = '';

        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '' || str_starts_with($trimmed, ';') || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^\[(.+)\]$/', $trimmed, $matches) === 1) {
                $currentSection = trim($matches[1]);
                $parsed[$currentSection] ??= [];
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

            $parsed[$currentSection][$key] = $this->unquoteValue($value);
        }

        return $this->buildResult($schema, $parsed, $warnings, true);
    }

    public function generate(array $schema, array $values): string
    {
        $fields = $schema['fields'] ?? [];
        $sections = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $section = trim((string) ($field['section'] ?? ''));
            $sections[$section][] = $field;
        }

        $lines = [];
        foreach ($sections as $section => $sectionFields) {
            if ($section !== '') {
                $lines[] = sprintf('[%s]', $section);
            }
            foreach ($sectionFields as $field) {
                $key = trim((string) ($field['key'] ?? $field['id'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $rawValue = $this->valueForField($field, $values);
                $value = $this->stringifyValue($rawValue, (string) ($field['type'] ?? 'string'));
                $lines[] = sprintf('%s=%s', $key, $value);
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }
}
