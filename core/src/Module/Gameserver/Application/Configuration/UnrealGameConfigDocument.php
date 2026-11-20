<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

/** Palworld OptionSettings: edit scalar tuple members without replacing the tuple. */
final class UnrealGameConfigDocument
{
    public static function addPlaceholder(string $content, string $key, string $type): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $section = '';
        foreach ($lines as &$line) {
            if (preg_match('/^\s*\[([^\]]+)\]\s*$/D', $line, $match)) {
                $section = $match[1];
            }
            $prefix = $section.'.OptionSettings.';
            if (str_starts_with($key, $prefix) && preg_match('/^(\s*OptionSettings\s*=\s*\()(.*)(\)\s*)$/D', $line, $match)) {
                $name = substr($key, strlen($prefix));
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
                    throw new \InvalidArgumentException('Ungültiger OptionSettings-Name.');
                }
                self::parts($match[2]);
                $placeholder = match ($type) {
                    'boolean' => 'False', 'integer', 'decimal' => '0', default => '""',
                };
                $line = $match[1].$match[2].('' === trim($match[2]) ? '' : ',').$name.'='.$placeholder.$match[3];
                break;
            }
        }
        unset($line);

        return implode(str_contains($content, "\r\n") ? "\r\n" : "\n", $lines);
    }

    /** @return list<string> */
    private static function parts(string $tuple): array
    {
        $parts = [];
        $start = 0;
        $quoted = false;
        $escaped = false;
        $depth = 0;
        for ($index = 0, $length = strlen($tuple); $index < $length; ++$index) {
            $char = $tuple[$index];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($quoted && '\\' === $char) {
                $escaped = true;
                continue;
            }
            if ('"' === $char) {
                $quoted = !$quoted;
                continue;
            }
            if ($quoted) {
                continue;
            }
            if ('(' === $char) {
                ++$depth;
            }
            if (')' === $char) {
                --$depth;
            }
            if ($depth < 0) {
                throw new \InvalidArgumentException('Ungültige OptionSettings-Struktur.');
            }
            if (',' === $char && 0 === $depth) {
                $parts[] = substr($tuple, $start, $index - $start);
                $start = $index + 1;
            }
        }
        if ($quoted || 0 !== $depth || $escaped) {
            throw new \InvalidArgumentException('Unvollständige OptionSettings-Struktur.');
        }
        $parts[] = substr($tuple, $start);

        return $parts;
    }

    /** @return array<string,string> */
    public static function values(string $content): array
    {
        $values = [];
        $section = '';
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $match)) {
                $section = $match[1].'.';
                continue;
            }
            if (!preg_match('/^\s*OptionSettings\s*=\s*\((.*)\)\s*$/D', $line, $match)) {
                continue;
            }
            foreach (self::parts($match[1]) as $part) {
                if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^()]*?)\s*$/D', $part, $member)) {
                    $raw = $member[2];
                    $values[$section.'OptionSettings.'.$member[1]] = str_starts_with($raw, '"') ? strtr(substr($raw, 1, -1), ['\\"' => '"', '\\\\' => '\\']) : $raw;
                }
            }
        }

        return $values;
    }

    /** @param array<string,string> $changes */
    public static function patch(string $content, array $changes): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $section = '';
        $found = [];
        foreach ($lines as &$line) {
            if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $match)) {
                $section = $match[1].'.';
                continue;
            }
            if (!preg_match('/^(\s*OptionSettings\s*=\s*\()(.*)(\)\s*)$/D', $line, $match)) {
                continue;
            }
            $parts = self::parts($match[2]);
            foreach ($parts as &$part) {
                if (!preg_match('/^(\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*)("(?:[^"\\\\]|\\\\.)*"|[^()]*?)(\s*)$/D', $part, $member)) {
                    continue;
                }
                $key = $section.'OptionSettings.'.$member[2];
                if (!array_key_exists($key, $changes)) {
                    continue;
                }
                $previous = $member[3];
                $value = $changes[$key];
                if (str_starts_with($previous, '"')) {
                    $encoded = '"'.addcslashes($value, '\\"').'"';
                } elseif (in_array(strtolower($previous), ['true', 'false'], true)) {
                    if (!in_array($value, ['true', 'false'], true)) {
                        throw new \InvalidArgumentException('Ungültiger OptionSettings-Schalter.');
                    }
                    $encoded = 'true' === $value ? 'True' : 'False';
                } elseif (is_numeric($previous)) {
                    if (!is_numeric($value) || !is_finite((float) $value)) {
                        throw new \InvalidArgumentException('Ungültiger OptionSettings-Zahlenwert.');
                    }
                    $encoded = $value;
                } else {
                    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value)) {
                        throw new \InvalidArgumentException('Ungültiger OptionSettings-Auswahlwert.');
                    }
                    $encoded = $value;
                }
                $part = $member[1].$encoded.$member[4];
                $found[$key] = true;
            }
            unset($part);
            $line = $match[1].implode(',', $parts).$match[3];
        }
        unset($line);
        if (array_diff_key($changes, $found)) {
            throw new \InvalidArgumentException('Unbekannter OptionSettings-Wert.');
        }

        return implode(str_contains($content, "\r\n") ? "\r\n" : "\n", $lines);
    }
}
