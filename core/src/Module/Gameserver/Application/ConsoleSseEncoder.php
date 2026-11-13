<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

final class ConsoleSseEncoder
{
    public static function encode(array $payload, string $event = 'chunk', ?int $id = null): string
    {
        $seq = max(0, $id ?? (int) ($payload['seq'] ?? 0));
        $event = in_array($event, ['chunk', 'status', 'ping'], true) ? $event : 'chunk';
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        return ($seq > 0 ? 'id: '.$seq."\n" : '').'event: '.$event."\n".'data: '.$json."\n\n";
    }
}
