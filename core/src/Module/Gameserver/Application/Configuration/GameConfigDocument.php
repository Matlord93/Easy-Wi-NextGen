<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

use Symfony\Component\Yaml\Yaml;

/** Edits scalar settings without dropping unrecognised text lines or JSON/YAML data. */
final class GameConfigDocument
{
    /** @return array<string,string> */
    public static function values(string $content, string $format, bool $includeContinuedProperties = false): array
    {
        if ('xml' === $format) {
            return XmlGameConfigDocument::values($content);
        }
        if ('unreal' === $format) {
            return UnrealGameConfigDocument::values($content);
        }
        if ('assignment' === $format) {
            return AssignmentGameConfigDocument::values($content);
        }
        if (in_array($format, ['json', 'jsonc', 'yaml', 'yml'], true)) {
            $data = in_array($format, ['json', 'jsonc'], true)
                ? self::optionalContainer(JsonGameConfigDocument::decode($content, 'jsonc' === $format))
                : self::optionalContainer(Yaml::parse($content, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_DATETIME));

            return null === $data ? [] : self::flatten($data);
        }
        if ('properties' === $format && $includeContinuedProperties) {
            $content = self::logicalProperties($content);
        }
        $values = [];
        $section = '';
        $continuation = false;
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            $skip = $continuation;
            $continuation = 'properties' === $format && ($skip || !self::propertyComment($line)) && self::continues($line);
            if ($skip || $continuation) {
                continue;
            }
            if ('ini' === $format && preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $m)) {
                $section = $m[1].'.';
                continue;
            }
            $entry = self::line($line, $format);
            if (null !== $entry && ('' !== $section || !ctype_digit($entry['key']))) {
                $values[$section.$entry['key']] = self::decode($entry['value'], $format);
            }
        }

        return $values;
    }

    /** @param array<string,string> $changes */
    public static function patch(string $content, string $format, array $changes): string
    {
        if ('xml' === $format) {
            return XmlGameConfigDocument::patch($content, $changes);
        }
        if ('unreal' === $format) {
            return UnrealGameConfigDocument::patch($content, $changes);
        }
        if ('assignment' === $format) {
            return AssignmentGameConfigDocument::patch($content, $changes);
        }
        if (in_array($format, ['json', 'jsonc'], true)) {
            $data = self::structuredContainer(JsonGameConfigDocument::decode($content, 'jsonc' === $format));
            foreach ($changes as $key => $value) {
                $data = self::setStructuredExisting($data, explode('.', $key), $value);
            }

            return JsonGameConfigDocument::encode($data, $content);
        }
        if (in_array($format, ['yaml', 'yml'], true)) {
            $data = self::structuredContainer(Yaml::parse($content, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_DATETIME));
            foreach ($changes as $key => $value) {
                $data = self::setStructuredExisting($data, explode('.', $key), $value);
            }

            return Yaml::dump($data, 16, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);
        }

        $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $section = '';
        $continuation = false;
        foreach ($lines as &$line) {
            $skip = $continuation;
            $continuation = 'properties' === $format && ($skip || !self::propertyComment($line)) && self::continues($line);
            if ($skip || $continuation) {
                continue;
            }
            if ('ini' === $format && preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $m)) {
                $section = $m[1].'.';
                continue;
            }
            $entry = self::line($line, $format);
            if (null !== $entry && array_key_exists($section.$entry['key'], $changes)) {
                $value = $changes[$section.$entry['key']];
                $line = $entry['prefix'].match ($format) {
                    'convar' => '"'.addcslashes($value, '\\"').'"',
                    'ini' => self::iniValue($value, $entry['quoted'] ?? false),
                    'flat' => $value,
                    'fivem' => '"'.addcslashes($value, '\\"').'"',
                    default => self::encode($value),
                }.$entry['suffix'];
            }
        }
        unset($line);

        return implode($newline, $lines);
    }

    /** @return array{key:string,value:string,prefix:string,suffix:string,quoted?:bool}|null */
    private static function line(string $line, string $format): ?array
    {
        if (preg_match('/^\s*(?:[#;!]|\/\/|$)/', $line) || preg_match('/(?<!\\\\)(?:\\\\\\\\)*\\\\$/', $line)) {
            return null;
        }
        if ('flat' === $format) {
            if (!preg_match('/^([ \t]*([A-Za-z_][A-Za-z0-9_.-]*)[ \t]*=[ \t]*)(.*)$/D', $line, $m)) {
                return null;
            }

            return ['key' => $m[2], 'value' => $m[3], 'prefix' => $m[1], 'suffix' => ''];
        }
        if ('fivem' === $format && preg_match('/^\s*(?:ensure|start|stop|restart|exec|add_ace|add_principal|remove_ace|remove_principal)\s/i', $line)) {
            return null;
        }
        if ('fivem' === $format && preg_match('/^(\s*(?:set|sets|setr)\s+)(.*)$/', $line, $directive)) {
            $entry = self::line($directive[2], 'convar');
            if (null !== $entry) {
                $entry['prefix'] = $directive[1].$entry['prefix'];
            }

            return $entry;
        }
        if (in_array($format, ['convar', 'fivem'], true)) {
            if (!preg_match('/^(\s*([A-Za-z_][A-Za-z0-9_.-]*)\s+)(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\r\n]*?))(\s*(?:\/\/.*)?)$/', $line, $m)) {
                return null;
            }

            return ['key' => $m[2], 'value' => '' !== $m[3] ? $m[3] : ($m[4] ?? ''), 'prefix' => $m[1], 'suffix' => $m[5]];
        }
        if ('properties' === $format) {
            if (!preg_match('/^([ \t\f]*((?:\\\\.|[^ \t\f=:\\\\])+))([ \t\f]*[=:][ \t\f]*|[ \t\f]+)?(.*)$/D', $line, $m)) {
                return null;
            }

            return ['key' => self::decode($m[2], $format), 'value' => $m[4], 'prefix' => $m[1].('' === $m[3] ? '=' : $m[3]), 'suffix' => ''];
        }
        $pattern = '/^(\s*([A-Za-z0-9_.-]+)\s*[=:]\s*)(.*)$/';
        if (!preg_match($pattern, $line, $m)) {
            return null;
        }
        if ('ini' === $format && preg_match('/^(?:"((?:[^"\\\\]|\\\\.)*)"|([^;#]*?))(\s*(?:[;#].*)?)$/', $m[3], $ini)) {
            return ['key' => $m[2], 'value' => '' !== $ini[1] ? $ini[1] : ($ini[2] ?? ''), 'prefix' => $m[1], 'suffix' => $ini[3], 'quoted' => str_starts_with(ltrim($m[3]), '"')];
        }

        return ['key' => $m[2], 'value' => $m[3], 'prefix' => $m[1], 'suffix' => ''];
    }

    private static function decode(string $value, string $format): string
    {
        if ('flat' === $format) {
            return $value;
        }
        if (in_array($format, ['convar', 'ini', 'fivem'], true)) {
            return strtr($value, ['\\"' => '"', '\\\\' => '\\']);
        }

        return preg_replace_callback('/(?:\\\\u[0-9a-fA-F]{4})+|\\\\./u', static function (array $m): string {
            $escape = substr($m[0], 1);
            if (str_starts_with($escape, 'u')) {
                $binary = '';
                foreach (explode('\\u', $m[0]) as $part) {
                    if ('' !== $part) {
                        $binary .= pack('n', (int) hexdec($part));
                    }
                }
                $decoded = mb_convert_encoding($binary, 'UTF-8', 'UTF-16BE');

                return false === $decoded ? '' : $decoded;
            }

            return match ($escape) {
                'n' => "\n", 'r' => "\r", 't' => "\t", 'f' => "\f", default => $escape,
            };
        }, $value) ?? $value;
    }

    private static function iniValue(string $value, bool $quoted): string
    {
        return $quoted || preg_match('/[;#"]|^\s|\s$/', $value) ? '"'.addcslashes($value, '\\"').'"' : $value;
    }

    private static function continues(string $line): bool
    {
        return 1 === preg_match('/(?<!\\\\)(?:\\\\\\\\)*\\\\$/', $line);
    }

    private static function propertyComment(string $line): bool
    {
        $line = ltrim($line, " \t\f");

        return str_starts_with($line, '#') || str_starts_with($line, '!');
    }

    /** Read Java logical lines for policy checks, while the simple editor leaves them untouched. */
    private static function logicalProperties(string $content): string
    {
        $logical = [];
        $pending = '';
        $continuation = false;
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            if (!$continuation && self::propertyComment($line)) {
                $logical[] = $line;
                continue;
            }
            $pending .= $continuation ? ltrim($line, " \t\f") : $line;
            $continuation = self::continues($pending);
            if ($continuation) {
                $pending = substr($pending, 0, -1);
            } else {
                $logical[] = $pending;
                $pending = '';
            }
        }
        if ($continuation) {
            $logical[] = $pending;
        }

        return implode("\n", $logical);
    }

    private static function encode(string $value): string
    {
        $value = strtr($value, ['\\' => '\\\\', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']);
        $spaces = strspn($value, ' ');
        $value = str_repeat('\\ ', $spaces).substr($value, $spaces);

        return preg_replace_callback('/[^\x20-\x7e]/u', static function (array $m): string {
            $encoded = mb_convert_encoding($m[0], 'UTF-16BE', 'UTF-8');
            $units = false === $encoded ? [] : unpack('n*', $encoded);
            if (false === $units) {
                return '';
            }
            $result = '';
            foreach (array_keys($units) as $key) {
                if (is_int($units[$key])) {
                    $result .= sprintf('\\u%04x', $units[$key]);
                }
            }

            return $result;
        }, $value) ?? $value;
    }

    /**
     * @param array<array-key,mixed>|\stdClass $data
     *
     * @return array<string,string>
     */
    private static function flatten(array|\stdClass $data, string $prefix = ''): array
    {
        $data = $data instanceof \stdClass ? get_object_vars($data) : $data;
        $values = [];
        foreach (array_keys($data) as $key) {
            if (str_contains((string) $key, '.') || ('' === $prefix && is_int($key) && !is_array($data[$key]) && !$data[$key] instanceof \stdClass)) {
                continue;
            }
            $name = $prefix.(string) $key;
            if (is_array($data[$key]) || $data[$key] instanceof \stdClass) {
                $values += self::flatten($data[$key], $name.'.');
            } elseif (is_scalar($data[$key])) {
                $values[$name] = is_bool($data[$key]) ? ($data[$key] ? 'true' : 'false') : (is_float($data[$key]) ? json_encode($data[$key], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) : (string) $data[$key]);
            }
        }

        return $values;
    }

    /** @return array<array-key,mixed>|\stdClass|null */
    private static function optionalContainer(mixed $value): array|\stdClass|null
    {
        return is_array($value) || $value instanceof \stdClass ? $value : null;
    }

    /** @return array<array-key,mixed>|\stdClass */
    private static function structuredContainer(mixed $value): array|\stdClass
    {
        if (is_array($value) || $value instanceof \stdClass) {
            return $value;
        }
        throw new \InvalidArgumentException('Diese Datei enthält keine bearbeitbare Konfiguration.');
    }

    /**
     * @param array<array-key,mixed>|\stdClass $data
     * @param list<string>                     $parts
     *
     * @return array<array-key,mixed>|\stdClass
     */
    private static function setStructuredExisting(array|\stdClass $data, array $parts, string $value): array|\stdClass
    {
        $key = array_shift($parts);
        $values = $data instanceof \stdClass ? get_object_vars($data) : $data;
        if (null === $key || !array_key_exists($key, $values)) {
            throw new \InvalidArgumentException('Unbekannter Konfigurationswert.');
        }
        if ([] !== $parts) {
            $values[$key] = self::setStructuredExisting(self::structuredContainer($values[$key]), $parts, $value);
        } else {
            $values[$key] = self::replaceScalar($values[$key], $value);
        }
        if ($data instanceof \stdClass) {
            // Assign just this property. Casting the whole tree to arrays would
            // turn {} and objects with consecutive numeric keys into JSON lists.
            $data = clone $data;
            $data->{$key} = $values[$key];

            return $data;
        }

        return $values;
    }

    private static function replaceScalar(mixed $previous, string $value): string|int|float|bool
    {
        if (is_bool($previous)) {
            if (!in_array($value, ['true', 'false'], true)) {
                throw new \InvalidArgumentException('Ungültiger Schalterwert.');
            }

            return 'true' === $value;
        }
        if (is_int($previous) || is_float($previous)) {
            if (!is_numeric($value) || !is_finite((float) $value)) {
                throw new \InvalidArgumentException('Ungültiger Zahlenwert.');
            }
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            return is_int($previous) && false !== $integer ? $integer : (float) $value;
        }
        if (is_string($previous)) {
            return $value;
        }
        throw new \InvalidArgumentException('Dieser Konfigurationswert ist kein einzelner Wert.');
    }
}
