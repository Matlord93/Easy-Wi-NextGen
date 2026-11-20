<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

/** Arma/DayZ scalar assignments, excluding class blocks, arrays and executable code. */
final class AssignmentGameConfigDocument
{
    /** @return list<array{key:string,line:int,start:int,length:int,value:string,quoted:bool}> */
    private static function entries(string $content): array
    {
        $masked = preg_replace_callback('/"(?:[^"\\\\]|\\\\.|"")*"|\/\/[^\r\n]*|\/\*.*?\*\//s', static fn (array $match): string => str_starts_with($match[0], '"') ? $match[0] : (preg_replace('/[^\r\n]/', ' ', $match[0]) ?? ''), $content) ?? $content;
        $entries = [];
        $depth = 0;
        foreach (preg_split('/\r\n|\n|\r/', $masked) ?: [] as $index => $line) {
            if (0 === $depth && preg_match('/^\s*([A-Za-z_][A-Za-z0-9_.-]*)\s*=\s*("(?:[^"\\\\]|\\\\.|"")*"|[^;]*?)(\s*;?\s*)$/D', $line, $match, PREG_OFFSET_CAPTURE)) {
                $value = $match[2][0];
                $quoted = str_starts_with($value, '"');
                if ($quoted || !preg_match('/[{}\[\]]/', $value)) {
                    $entries[] = ['key' => $match[1][0], 'line' => $index, 'start' => $match[2][1], 'length' => strlen($value), 'value' => $quoted ? strtr(substr($value, 1, -1), ['""' => '"', '\\"' => '"', '\\\\' => '\\']) : trim($value), 'quoted' => $quoted];
                }
            }
            $structure = preg_replace('/"(?:[^"\\\\]|\\\\.|"")*"/', '', $line) ?? $line;
            $depth += substr_count($structure, '{') - substr_count($structure, '}');
            if ($depth < 0) {
                throw new \InvalidArgumentException('Ungültige Klassenstruktur in der Spielkonfiguration.');
            }
        }

        return $entries;
    }

    /** @return array<string,string> */
    public static function values(string $content): array
    {
        $values = [];
        foreach (self::entries($content) as $entry) {
            $values[$entry['key']] = $entry['value'];
        }

        return $values;
    }

    /** @param array<string,string> $changes */
    public static function patch(string $content, array $changes): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        foreach (self::entries($content) as $entry) {
            if (!array_key_exists($entry['key'], $changes)) {
                continue;
            }
            $value = $changes[$entry['key']];
            if (!$entry['quoted'] && !is_numeric($value) && !in_array(strtolower($value), ['true', 'false'], true)) {
                throw new \InvalidArgumentException('Dieser Spielparameter erfordert eine Zahl oder einen Schalter.');
            }
            $encoded = $entry['quoted'] ? '"'.str_replace('"', '""', $value).'"' : $value;
            $lines[$entry['line']] = substr_replace($lines[$entry['line']], $encoded, $entry['start'], $entry['length']);
        }

        return implode(str_contains($content, "\r\n") ? "\r\n" : "\n", $lines);
    }
}
