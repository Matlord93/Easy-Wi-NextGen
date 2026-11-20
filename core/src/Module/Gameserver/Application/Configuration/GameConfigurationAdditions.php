<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

use Symfony\Component\Yaml\Yaml;

/** Adds only server-validated profile fields; normal patches still require existing values. */
final class GameConfigurationAdditions
{
    public static function canAdd(string $content, string $format, string $key): bool
    {
        if (in_array($format, ['json', 'jsonc', 'yaml', 'yml'], true)) {
            $data = self::structured($content, $format);
            foreach (explode('.', $key) as $part) {
                if (!$data instanceof \stdClass) {
                    return false;
                }
                if (!property_exists($data, $part)) {
                    return true;
                }
                $data = self::asObject($data->{$part});
            }

            return false;
        }
        if ('unreal' === $format) {
            $prefix = substr($key, 0, (int) strrpos($key, '.') + 1);
            foreach (array_keys(GameConfigDocument::values($content, $format)) as $existing) {
                if (str_starts_with($existing, $prefix)) {
                    return true;
                }
            }

            return false;
        }

        return in_array($format, ['convar', 'properties', 'flat', 'ini', 'assignment', 'xml', 'fivem'], true);
    }

    private static function asObject(mixed $value): ?\stdClass
    {
        return $value instanceof \stdClass ? $value : null;
    }

    private static function structured(string $content, string $format): ?\stdClass
    {
        return self::asObject(in_array($format, ['json', 'jsonc'], true) ? JsonGameConfigDocument::decode($content, 'jsonc' === $format) : Yaml::parse($content, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_DATETIME));
    }

    /** @param array<string,string> $changes
     * @param list<array{key:string,type:string,native_type?:string,...}> $available
     */
    public static function prepare(string $content, string $format, array $changes, array $available): string
    {
        $fields = array_column($available, null, 'key');
        $new = array_intersect_key($changes, $fields);
        if ([] === $new) {
            return $content;
        }
        $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
        foreach (array_keys($new) as $key) {
            if (!preg_match('/^[A-Za-z0-9_\/.\[\]-]+$/D', $key) || !self::canAdd($content, $format, $key)) {
                throw new \InvalidArgumentException('Diese Einstellung kann nicht ergänzt werden: '.$key);
            }
            $type = $fields[$key]['native_type'] ?? $fields[$key]['type'];
            $placeholder = match ($type) {
                'integer', 'decimal' => '0', 'boolean' => 'false', default => '',
            };
            if (in_array($format, ['json', 'jsonc', 'yaml', 'yml'], true)) {
                $data = self::structured($content, $format);
                if (!$data instanceof \stdClass) {
                    throw new \InvalidArgumentException('Die Konfiguration muss ein Objekt sein.');
                }
                $target = $data;
                $parts = explode('.', $key);
                $name = array_pop($parts);
                foreach ($parts as $part) {
                    if (!property_exists($target, $part)) {
                        $target->{$part} = new \stdClass();
                    }
                    $target = self::asObject($target->{$part}) ?? throw new \InvalidArgumentException('Vorhandene Listen und Strukturen bleiben erhalten.');
                }
                if (property_exists($target, $name)) {
                    throw new \InvalidArgumentException('Die Einstellung ist bereits vorhanden.');
                }
                $target->{$name} = match ($type) {
                    'boolean' => false, 'integer' => 0, 'decimal' => 0.0, default => '',
                };
                $content = in_array($format, ['json', 'jsonc'], true) ? JsonGameConfigDocument::encode($data, $content) : Yaml::dump($data, 16, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);
            } elseif ('unreal' === $format) {
                $content = UnrealGameConfigDocument::addPlaceholder($content, $key, $type);
            } elseif ('xml' === $format) {
                $content = XmlGameConfigDocument::addPlaceholder($content, $key);
            } elseif ('ini' === $format) {
                $position = strrpos($key, '.');
                $section = false === $position ? '' : substr($key, 0, $position);
                $name = false === $position ? $key : substr($key, $position + 1);
                $line = $name.'='.$placeholder;
                $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
                $current = '';
                $insert = null;
                foreach ($lines as $index => $text) {
                    if (preg_match('/^\s*\[([^\]]+)\]\s*$/D', $text, $match)) {
                        if ($current === $section && null === $insert) {
                            $insert = $index;
                        }
                        $current = $match[1];
                    }
                }
                if (null === $insert && $current === $section) {
                    $insert = count($lines);
                }
                if (null === $insert) {
                    $lines[] = '['.$section.']';
                    $lines[] = $line;
                } else {
                    array_splice($lines, $insert, 0, [$line]);
                }
                $content = implode($newline, $lines).$newline;
            } else {
                $line = match ($format) {
                    'convar' => $key.' ""',
                    'fivem' => (in_array(strtolower($key), ['sv_projectname', 'sv_projectdesc', 'tags', 'locale'], true) ? 'sets ' : 'set ').$key.' ""',
                    'assignment' => $key.' = '.(in_array($type, ['string', 'password'], true) ? '""' : $placeholder).';',
                    default => $key.'='.$placeholder,
                };
                $content = rtrim($content, "\r\n").$newline.$line.$newline;
            }
        }
        if (array_diff_key($new, GameConfigDocument::values($content, $format))) {
            throw new \InvalidArgumentException('Die neuen Einstellungen konnten nicht sicher in die Dateistruktur eingefügt werden.');
        }

        return $content;
    }
}
