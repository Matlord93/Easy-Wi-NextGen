<?php

declare(strict_types=1);

namespace App\Module\Core\Application\ConfigSchema;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;

final class IniConfigSchemaParser extends AbstractConfigSchemaParser
{
    #[\Override]
    public function format(): string
    {
        return 'ini';
    }

    #[\Override]
    public function parse(string $content, array $schema): ConfigSchemaParseResult
    {
        $parsed = [];
        $warnings = [];
        $currentSection = '';

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $trimmed = trim($line);
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

            [$key, $value] = array_pad(explode('=', $trimmed, 2), 2, '');
            $key = trim($key);
            if ($key === '') {
                $warnings[] = sprintf('Unrecognized line: %s', $trimmed);
                continue;
            }

            $parsed[$currentSection][$key] = $this->unquoteValue($value);
        }

        return $this->buildResult($schema, $parsed, $warnings, true);
    }

    #[\Override]
    public function generate(array $schema, array $values): string
    {
        $fields = JsonArray::orEmpty($schema['fields'] ?? null);
        $sections = [];

        foreach (array_keys($fields) as $fieldIndex) {
            $field = JsonMap::fromValue($fields[$fieldIndex]);
            if ($field === null) {
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
                $value = $this->stringifyValue($this->valueForField($field, $values), (string) ($field['type'] ?? 'string'));
                $lines[] = sprintf('%s=%s', $key, $value);
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }
}
