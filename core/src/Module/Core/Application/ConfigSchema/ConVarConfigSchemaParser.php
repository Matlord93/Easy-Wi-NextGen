<?php

declare(strict_types=1);

namespace App\Module\Core\Application\ConfigSchema;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;

final class ConVarConfigSchemaParser extends AbstractConfigSchemaParser
{
    #[\Override]
    public function format(): string
    {
        return 'convar';
    }

    #[\Override]
    public function parse(string $content, array $schema): ConfigSchemaParseResult
    {
        $parsed = [];
        $warnings = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $trimmed = trim($line);
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

    #[\Override]
    public function generate(array $schema, array $values): string
    {
        $fields = JsonArray::orEmpty($schema['fields'] ?? null);
        $lines = [];

        foreach (array_keys($fields) as $fieldIndex) {
            $field = JsonMap::fromValue($fields[$fieldIndex]);
            if ($field === null) {
                continue;
            }
            $key = trim((string) ($field['key'] ?? $field['id'] ?? ''));
            if ($key === '') {
                continue;
            }
            $value = $this->stringifyValue($this->valueForField($field, $values), (string) ($field['type'] ?? 'string'));
            if (preg_match('/\s/', $value) === 1) {
                $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
                $value = sprintf('"%s"', $escaped);
            }
            $lines[] = sprintf('%s %s', $key, $value);
        }

        return implode("\n", $lines) . "\n";
    }
}
