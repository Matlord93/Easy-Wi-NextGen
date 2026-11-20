<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

/** Native V Rising files may contain a UTF-8 BOM, comments and trailing commas. */
final class JsonGameConfigDocument
{
    public static function decode(string $content, bool $relaxed = false): mixed
    {
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
        if ($relaxed) {
            $content = self::normalise(self::normalise($content, false), true);
        }

        return json_decode($content, false, 64, JSON_THROW_ON_ERROR);
    }

    /** @param \stdClass|array<array-key,mixed> $data */
    public static function encode(\stdClass|array $data, string $original): string
    {
        $bom = str_starts_with($original, "\xEF\xBB\xBF") ? "\xEF\xBB\xBF" : '';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return $bom.$json."\n";
    }

    private static function normalise(string $content, bool $commas): string
    {
        $result = '';
        $length = strlen($content);
        $last = '';
        for ($i = 0; $i < $length; ++$i) {
            $char = $content[$i];
            if ('"' === $char) {
                $result .= $char;
                while (++$i < $length) {
                    $result .= $content[$i];
                    if ('\\' === $content[$i] && $i + 1 < $length) {
                        $result .= $content[++$i];
                    } elseif ('"' === $content[$i]) {
                        break;
                    }
                }
                $last = '"';
            } elseif (!$commas && '/' === $char && $i + 1 < $length && in_array($content[$i + 1], ['/', '*'], true)) {
                $block = '*' === $content[++$i];
                $end = $block ? strpos($content, '*/', $i + 1) : strcspn($content, "\r\n", $i + 1) + $i + 1;
                if (false === $end) {
                    throw new \JsonException('Nicht abgeschlossener JSON-Kommentar.');
                }
                // Whitespace prevents adjacent JSON tokens from being joined.
                $result .= ' ';
                $i = $block ? $end + 1 : $end - 1;
            } elseif ($commas && ',' === $char && !in_array($last, ['', '[', '{', ':', ','], true) && in_array($content[$i + 1 + strspn($content, " \t\r\n", $i + 1)] ?? '', [']', '}'], true)) {
                continue;
            } else {
                $result .= $char;
                if (!in_array($char, [' ', "\t", "\r", "\n"], true)) {
                    $last = $char;
                }
            }
        }

        return $result;
    }
}
