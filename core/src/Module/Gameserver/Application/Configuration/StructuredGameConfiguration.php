<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Domain\Entity\Instance;
use App\Repository\ConfigSchemaRepository;
use App\Repository\GameDefinitionRepository;

/**
 * @psalm-type Field = array{key:string,label:string,description:string,category:string,type:string,value:string,readonly:bool,min:int|float|null,max:int|float|null,options:list<string>,option_labels:array<array-key,string>,divisor:int,native_type:string}
 * @psalm-type FileDefinition = array{label:string,format:string,path?:string,schema:array<string,array<string,mixed>>}
 */
final readonly class StructuredGameConfiguration
{
    public function __construct(private ConfigSchemaRepository $schemas, private GameDefinitionRepository $games)
    {
    }

    /** @return array<string,FileDefinition> */
    public function files(Instance $instance): array
    {
        $files = [];
        $resolver = JsonMap::strings($instance->getTemplate()->getInstallResolver())['type'] ?? '';
        if (in_array($resolver, ['minecraft_vanilla', 'papermc_paper', 'minecraft_spigot', 'minecraft_bedrock'], true)) {
            $files['server.properties'] = ['label' => 'server.properties', 'format' => 'properties', 'schema' => []];
        }
        $configFiles = $instance->getTemplate()->getConfigFiles();
        foreach (array_keys($configFiles) as $index) {
            $strings = JsonMap::strings($configFiles[$index]);
            foreach (GameSetupProfiles::configurationPaths($instance->getTemplate()->getGameKey(), $strings['path'] ?? '') as $path) {
                $format = GameSetupProfiles::format($instance->getTemplate()->getGameKey(), $path) ?? strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (self::relative($path) && in_array($format, ['properties', 'cfg', 'conf', 'convar', 'ini', 'json', 'jsonc', 'yaml', 'yml', 'txt', 'xml', 'unreal', 'assignment', 'flat', 'fivem'], true)) {
                    $files[$path] = ['path' => $path, 'label' => $path !== ($strings['path'] ?? '') ? basename($path) : ($strings['description'] ?? $path), 'format' => in_array($format, ['cfg', 'conf'], true) ? 'convar' : $format, 'schema' => []];
                }
            }
        }
        $game = $this->games->findOneBy(['gameKey' => $instance->getTemplate()->getGameKey()]);
        if (null !== $game) {
            foreach ($this->schemas->findByGameDefinition($game) as $schema) {
                $path = $schema->getFilePath();
                $format = strtolower($schema->getFormat());
                $format = match ($format) {
                    'key_value','keyvalue' => 'properties', 'convar_lines' => 'convar', default => $format,
                };
                if (!self::relative($path) || !in_array($format, ['ini', 'properties', 'convar', 'json', 'jsonc', 'yaml', 'yml', 'xml', 'unreal', 'assignment', 'flat', 'fivem'], true)) {
                    continue;
                }
                $format = GameSetupProfiles::format($instance->getTemplate()->getGameKey(), $path) ?? $format;
                $definition = ['path' => $path, 'label' => $schema->getName(), 'format' => $format, 'schema' => []];
                $data = JsonMap::fromValue($schema->getSchema()) ?? [];
                $schemaFields = \App\Infrastructure\Config\JsonArray::orEmpty($data['fields'] ?? null);
                foreach (array_keys($schemaFields) as $index) {
                    $field = JsonMap::fromValue($schemaFields[$index]);
                    if (null === $field) {
                        continue;
                    }
                    $text = JsonMap::strings($field);
                    $key = isset($text['key']) ? ((isset($text['section']) ? $text['section'].'.' : '').$text['key']) : ($text['id'] ?? '');
                    if ('' !== $key) {
                        $definition['schema'][$key] = $field;
                    }
                }
                $files[$path] = $definition;
            }
        }

        return array_slice($files, 0, 50, true);
    }

    /**
     * @param FileDefinition $file
     *
     * @return list<Field>
     */
    public function fields(Instance $instance, array $file, string $content): array
    {
        return $this->describe($instance, $file, GameConfigDocument::values($content, $file['format']));
    }

    /** @param FileDefinition $file
     * @return list<Field>
     */
    public function availableFields(Instance $instance, array $file, string $content): array
    {
        $caseSensitive = in_array($file['format'], ['json', 'jsonc', 'yaml', 'yml', 'properties', 'flat', 'xml'], true);
        $existing = GameConfigDocument::values($content, $file['format'], true);
        if (!$caseSensitive) {
            $existing = array_change_key_case($existing, CASE_LOWER);
        }
        $definitions = JsonMap::fromValue(GameSetupProfiles::file($instance->getTemplate()->getGameKey(), $file['path'] ?? $file['label'])['fields'] ?? null) ?? [];
        $values = [];
        foreach (array_keys($definitions) as $key) {
            $meta = JsonMap::fromValue($definitions[$key]) ?? [];
            $text = JsonMap::strings($meta);
            $writeKey = $text['write_key'] ?? '';
            if (true !== ($meta['addable'] ?? false) || '' === $writeKey || array_key_exists($caseSensitive ? $writeKey : strtolower($writeKey), $existing)) {
                continue;
            }
            // Complex values already present in the document must never be
            // replaced by a new scalar just because flatten() omits them.
            if (!GameConfigurationAdditions::canAdd($content, $file['format'], $writeKey)) {
                continue;
            }
            $values[$writeKey] = match ($text['type'] ?? 'string') {
                'boolean' => 'false', 'integer', 'decimal' => '0',
                'enum' => 'integer' === ($text['enum_storage'] ?? '') ? '0' : '',
                default => '',
            };
        }

        return array_values(array_filter($this->describe($instance, $file, $values), static fn (array $field): bool => !$field['readonly']));
    }

    /** @param FileDefinition $file
     * @param array<string,string> $values
     *
     * @return list<Field>
     */
    private function describe(Instance $instance, array $file, array $values): array
    {
        $gameKey = $instance->getTemplate()->getGameKey();
        $known = str_starts_with($gameKey, 'minecraft_') && !str_contains($gameKey, 'bedrock') && !str_contains($gameKey, 'bungeecord') ? GameConfigurationFields::descriptions() : [];
        $path = $file['path'] ?? $file['label'];
        $fields = [];
        $managesTags = EnshroudedServerTags::manages($instance, $file);
        $enshrouded = EnshroudedConfiguration::supports($instance, $path);
        foreach ($values as $key => $value) {
            if (VRisingProgressionConfiguration::ownsField($instance, $path, $key)
                || ($managesTags && EnshroudedServerTags::isTagField($key))
                || ($enshrouded && EnshroudedBanList::ownsField($key))) {
                continue;
            }
            if ('convar' === $file['format'] && !SourceServerSetup::canEditConvar($instance->getTemplate(), $key)) {
                continue;
            }
            $meta = array_replace(GameSetupProfiles::field($gameKey, $path, $key), $file['schema'][$key] ?? []);
            if (true === ($meta['hidden'] ?? false)) {
                continue;
            }
            $text = JsonMap::strings($meta);
            $lower = strtolower($key);
            $description = $known[$lower] ?? null;
            $boolean = in_array(strtolower($value), ['true', 'false'], true);
            $integer = 1 === preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value);
            $type = $text['type'] ?? ($boolean ? 'boolean' : ($integer ? 'integer' : (is_numeric($value) ? 'decimal' : 'string')));
            $type = match ($type) {
                'bool','toggle' => 'boolean', 'int' => 'integer', 'number','float','double' => 'decimal', default => in_array($type, ['boolean', 'integer', 'decimal', 'string', 'password'], true) ? $type : 'string',
            };
            $options = $description[3] ?? [];
            $schemaOptions = \App\Infrastructure\Config\JsonArray::fromValue($meta['options'] ?? $meta['allowed'] ?? null);
            if (null !== $schemaOptions) {
                $options = [];
                foreach (array_keys($schemaOptions) as $index) {
                    if (is_scalar($schemaOptions[$index])) {
                        $options[] = (string) $schemaOptions[$index];
                    } elseif (is_array($schemaOptions[$index]) && is_scalar($schemaOptions[$index]['value'] ?? null)) {
                        $options[] = (string) $schemaOptions[$index]['value'];
                    }
                }
            }
            $optionLabels = [];
            if ('enum' === ($text['type'] ?? '')) {
                $enum = JsonMap::fromValue($meta['enum_values'] ?? null) ?? [];
                $labels = JsonMap::strings(JsonMap::fromValue($meta['enum_labels'] ?? null) ?? []);
                $numeric = 1 === preg_match('/^-?[0-9]+$/D', $value);
                $type = $numeric ? 'integer' : 'string';
                $enumOptions = [];
                foreach (array_keys($enum) as $name) {
                    if (!is_int($enum[$name])) {
                        continue;
                    }
                    $option = $numeric ? (string) $enum[$name] : $name;
                    $enumOptions[] = $option;
                    $optionLabels[$option] = $labels[$name] ?? $name;
                }
                if (null === $schemaOptions) {
                    $options = $enumOptions;
                }
            }
            if ('boolean' === $type && in_array($value, ['0', '1'], true)) {
                // Convar/INI games often use 0/1 instead of Java's true/false.
                $type = 'string';
                $options = ['0', '1'];
            }
            $slots = GameConfigurationFields::slots($key);
            $reserve = VRisingHostConfiguration::isAdminReserve($instance, $path, $key);
            $capacity = $slots || $reserve;
            $readonly = GameConfigurationFields::managed($key) || GameSetupProfiles::managed($gameKey, $path, $key, $instance->getTemplate()) || filter_var($meta['readonly'] ?? false, FILTER_VALIDATE_BOOLEAN) || ($capacity && $instance->isLockSlots());
            $secret = 'boolean' !== $type && !in_array($text['type'] ?? '', ['boolean', 'bool', 'toggle'], true) && (str_contains($lower, 'password') || str_contains($lower, 'secret') || str_contains($lower, 'token'));
            $minimum = self::numericBound($meta['min'] ?? null);
            $maximum = self::numericBound($meta['max'] ?? null);
            $roleLimit = EnshroudedConfiguration::roleLimit($instance, $path, $key, $values);
            if (null !== $roleLimit) {
                $maximum = min($maximum ?? $roleLimit, $roleLimit);
            }
            if ('integer' === $type) {
                $minimum = is_float($minimum) ? ceil($minimum) : $minimum;
                $maximum = is_float($maximum) ? floor($maximum) : $maximum;
            }
            if ('boolean' === $type && in_array(strtolower($value), ['true', 'false'], true)) {
                $value = strtolower($value);
            }
            $nativeType = $capacity ? 'integer' : ($secret ? 'password' : $type);
            $configuredDivisor = self::numericBound($meta['display_divisor'] ?? null);
            $divisor = is_int($configuredDivisor) && $configuredDivisor > 1 && !$capacity && in_array($nativeType, ['integer', 'decimal'], true) ? $configuredDivisor : 1;
            if ($divisor > 1) {
                if (is_numeric($value) && is_finite((float) $value)) {
                    $value = (string) ((float) $value / (float) $divisor);
                }
                $type = 'decimal';
                $minimum = null === $minimum ? null : (float) $minimum / (float) $divisor;
                $maximum = null === $maximum ? null : (float) $maximum / (float) $divisor;
            }
            $fields[] = ['key' => $key, 'label' => $text['label'] ?? $description[0] ?? $key, 'description' => $text['description'] ?? $description[1] ?? 'Spielabhängiger Wert aus der vorhandenen Konfigurationsdatei. Änderungen werden nach einem Serverneustart wirksam.', 'category' => JsonMap::strings($file['schema'][$key] ?? [])['category'] ?? EnshroudedConfiguration::roleCategory($instance, $path, $key, $values) ?? $text['category'] ?? $description[2] ?? 'Erweitert', 'type' => $capacity ? 'integer' : ($secret ? 'password' : $type), 'value' => $readonly && $secret ? '' : $value, 'readonly' => $readonly, 'min' => $slots ? 1 : ($reserve ? 0 : $minimum), 'max' => $capacity ? min($instance->getMaxSlots(), $maximum ?? $instance->getMaxSlots()) : $maximum, 'options' => $options, 'option_labels' => $optionLabels, 'divisor' => $divisor, 'native_type' => $nativeType];
        }

        return $fields;
    }

    /**
     * @param list<Field>         $fields
     * @param array<string,mixed> $input
     *
     * @return array<string,string>
     */
    public function validate(array $fields, array $input): array
    {
        $byKey = array_column($fields, null, 'key');
        $slotValue = null;
        $changes = [];
        foreach (array_keys($input) as $inputKey) {
            $key = $inputKey;
            $field = $byKey[$key] ?? null;
            if (null === $field || $field['readonly']) {
                throw new \InvalidArgumentException('Dieser Wert wird vom Panel verwaltet oder ist nicht in der Datei vorhanden: '.$key);
            }
            if (!is_scalar($input[$inputKey]) || strlen((string) $input[$inputKey]) > 8192 || preg_match('/[\x00\r\n]/', (string) $input[$inputKey])) {
                throw new \InvalidArgumentException('Ungültiger Wert für '.$field['label']);
            }
            $value = is_bool($input[$inputKey]) ? ($input[$inputKey] ? 'true' : 'false') : (string) $input[$inputKey];
            if ('boolean' === $field['type'] && !in_array($value, ['true', 'false'], true)) {
                throw new \InvalidArgumentException('Bitte einen gültigen Schalterwert wählen: '.$field['label']);
            }
            $number = null;
            if ('decimal' === $field['type']) {
                if (!is_numeric($value) || !is_finite((float) $value)) {
                    throw new \InvalidArgumentException('Bitte eine gültige Zahl eingeben: '.$field['label']);
                }
                $number = (float) $value;
            } elseif ('integer' === $field['type']) {
                $integer = filter_var($value, FILTER_VALIDATE_INT);
                if (false === $integer) {
                    throw new \InvalidArgumentException('Bitte eine gültige ganze Zahl eingeben: '.$field['label']);
                }
                $number = $integer;
            }
            if (null !== $number && ((null !== $field['min'] && $number < $field['min']) || (null !== $field['max'] && $number > $field['max']))) {
                throw new \InvalidArgumentException('Wert außerhalb des erlaubten Bereichs: '.$field['label']);
            }
            if ([] !== $field['options'] && !in_array($value, $field['options'], true)) {
                throw new \InvalidArgumentException('Ungültige Auswahl: '.$field['label']);
            }
            if (GameConfigurationFields::slots($key)) {
                if (null !== $slotValue && $slotValue !== $value) {
                    throw new \InvalidArgumentException('Alle Spielerlimits müssen denselben Wert verwenden.');
                }
                $slotValue = $value;
            }
            if ($field['divisor'] > 1) {
                $scaled = (float) $value * (float) $field['divisor'];
                if (!is_finite($scaled)) {
                    throw new \InvalidArgumentException('Ungültige Zeitdauer: '.$field['label']);
                }
                $value = 'integer' === $field['native_type'] ? sprintf('%.0F', round($scaled)) : (string) $scaled;
                if ('integer' === $field['native_type'] && false === filter_var($value, FILTER_VALIDATE_INT)) {
                    throw new \InvalidArgumentException('Zeitdauer außerhalb des unterstützten Ganzzahlbereichs.');
                }
            }
            $changes[$key] = $value;
        }

        return $changes;
    }

    private static function numericBound(mixed $value): int|float|null
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if (is_string($value) && is_numeric($value)) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if (false !== $integer) {
                return $integer;
            }
            $decimal = (float) $value;

            return is_finite($decimal) ? $decimal : null;
        }

        return null;
    }

    private static function relative(string $path): bool
    {
        return '' !== $path && !str_starts_with($path, '/') && !str_contains($path, '\\') && !str_contains($path, ':') && !in_array('..', explode('/', $path), true) && !preg_match('/[\x00-\x1f]/', $path);
    }
}
