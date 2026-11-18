<?php

declare(strict_types=1);

namespace App\Module\Core\Application\ConfigSchema;

use App\Module\Core\Domain\Entity\ConfigSchema;
use App\Infrastructure\Config\JsonMap;

final class ConfigSchemaService
{
    public function __construct(
        private readonly IniConfigSchemaParser $iniParser,
        private readonly KeyValueConfigSchemaParser $keyValueParser,
        private readonly ConVarConfigSchemaParser $conVarParser,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeSchema(ConfigSchema $configSchema): array
    {
        return $this->normalizeSchemaArray(JsonMap::fromValue($configSchema->getSchema()) ?? [], $configSchema->getFormat());
    }

    public function parse(ConfigSchema $configSchema, string $content): ConfigSchemaParseResult
    {
        $schema = $this->normalizeSchema($configSchema);

        return $this->getParser($configSchema->getFormat())->parse($content, $schema);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function generate(ConfigSchema $configSchema, array $values): string
    {
        $schema = $this->normalizeSchema($configSchema);

        return $this->getParser($configSchema->getFormat())->generate($schema, $values);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function normalizeSchemaArray(array $schema, string $format): array
    {
        $description = (string) ($schema['description'] ?? '');
        $normalizedFields = [];
        foreach ($schema['fields'] ?? [] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string) ($field['key'] ?? ''));
            $section = trim((string) ($field['section'] ?? ''));
            $id = trim((string) ($field['id'] ?? ''));
            if ($id === '') {
                $id = $section !== '' ? $section . '.' . $key : $key;
            }

            $normalized = [
                'id' => $id,
                'key' => $key !== '' ? $key : $id,
                'section' => $section !== '' ? $section : null,
                'label' => (string) ($field['label'] ?? $field['name'] ?? $key),
                'description' => (string) ($field['description'] ?? ''),
                'type' => (string) ($field['type'] ?? 'string'),
            ];

            if (array_key_exists('default', $field)) {
                self::setField($normalized, 'default', $field['default']);
            }
            if (isset($field['options']) && is_array($field['options'])) {
                $normalized['options'] = $field['options'];
            }

            $normalizedFields[] = $normalized;
        }

        return [
            'format' => $format,
            'description' => $description,
            'fields' => $normalizedFields,
        ];
    }

    private function getParser(string $format): ConfigSchemaParserInterface
    {
        $format = match (strtolower($format)) {
            'keyvalue' => 'key_value',
            'convar_lines' => 'convar',
            default => strtolower($format),
        };
        foreach ([$this->iniParser, $this->keyValueParser, $this->conVarParser] as $parser) {
            if ($parser->format() === $format) {
                return $parser;
            }
        }
        return $this->keyValueParser;
    }
    /**
     * @template T
     * @param array<string,T> $fields
     * @param T $value
     */
    private static function setField(array &$fields, string $key, mixed $value): void
    {
        $fields[$key] = $value;
    }
}
