<?php

declare(strict_types=1);

namespace App\Module\Core\Application\ConfigSchema;

use App\Module\Core\Domain\Entity\ConfigSchema;

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
        return $this->normalizeSchemaArray($configSchema->getSchema(), $configSchema->getFormat());
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
                $normalized['default'] = $field['default'];
            }
            if (isset($field['options']) && is_array($field['options'])) {
                $normalized['options'] = $field['options'];
            }

            $normalizedFields[] = $normalized;
        }

        return [
            'format' => $format,
            'fields' => $normalizedFields,
        ];
    }

    private function getParser(string $format): ConfigSchemaParserInterface
    {
        return match (strtolower($format)) {
            'ini' => $this->iniParser,
            'key_value', 'keyvalue' => $this->keyValueParser,
            'convar', 'convar_lines' => $this->conVarParser,
            default => $this->keyValueParser,
        };
    }
}
