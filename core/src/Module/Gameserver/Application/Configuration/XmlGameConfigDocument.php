<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

/** 7 Days to Die property elements; other elements and comments remain intact. */
final class XmlGameConfigDocument
{
    public static function addPlaceholder(string $content, string $key): string
    {
        $document = self::parse($content);
        $element = $document->createElement('property');
        $element->setAttribute('name', $key);
        $element->setAttribute('value', '');
        $document->documentElement?->appendChild($element);
        $saved = $document->saveXML();
        if (false === $saved) {
            throw new \InvalidArgumentException('Die XML-Einstellung konnte nicht ergänzt werden.');
        }

        return $saved;
    }

    private static function parse(string $content): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            $document->preserveWhiteSpace = true;
            if ('' === $content || '' === trim($content) || !$document->loadXML($content, LIBXML_NONET) || null !== $document->doctype || 'ServerSettings' !== $document->documentElement?->tagName) {
                throw new \InvalidArgumentException('Ungültige ServerSettings-XML-Datei. DTDs und externe Entitäten sind nicht erlaubt.');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<string,string> */
    public static function values(string $content): array
    {
        $values = [];
        $nodes = self::parse($content)->getElementsByTagName('property');
        for ($index = 0; $index < $nodes->length; ++$index) {
            $element = $nodes->item($index);
            if (!$element instanceof \DOMElement) {
                continue;
            }
            if ($element->hasAttribute('name') && $element->hasAttribute('value')) {
                $values[$element->getAttribute('name')] = $element->getAttribute('value');
            }
        }

        return $values;
    }

    /** @param array<string,string> $changes */
    public static function patch(string $content, array $changes): string
    {
        $document = self::parse($content);
        foreach ($changes as $value) {
            if (1 !== preg_match('/^[\x09\x0a\x0d\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*$/uD', $value)) {
                throw new \InvalidArgumentException('Dieser Wert enthält Zeichen, die in XML nicht zulässig sind.');
            }
        }
        $found = [];
        $nodes = $document->getElementsByTagName('property');
        for ($index = 0; $index < $nodes->length; ++$index) {
            $element = $nodes->item($index);
            if (!$element instanceof \DOMElement) {
                continue;
            }
            $key = $element->getAttribute('name');
            if (array_key_exists($key, $changes) && $element->hasAttribute('value')) {
                $element->setAttribute('value', $changes[$key]);
                $found[$key] = true;
            }
        }
        if (array_diff_key($changes, $found)) {
            throw new \InvalidArgumentException('Unbekannter XML-Konfigurationswert.');
        }
        $saved = $document->saveXML();
        if (false === $saved) {
            throw new \InvalidArgumentException('Die XML-Datei konnte nicht gespeichert werden.');
        }

        return $saved;
    }
}
