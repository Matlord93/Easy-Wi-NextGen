<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

use App\Module\Core\Domain\Entity\Instance;

final class VRisingGameConfiguration
{
    public static function canInitialize(Instance $instance, string $path): bool
    {
        return in_array(strtolower($instance->getTemplate()->getGameKey()), ['v_rising', 'v_rising_windows'], true)
            && 'servergamesettings.json' === strtolower(basename(str_replace('\\', '/', $path)));
    }

    public static function initialConfig(Instance $instance, string $path): string
    {
        if (!self::canInitialize($instance, $path)) {
            throw new \InvalidArgumentException('Für diese Datei ist keine Spielregel-Konfiguration vorgesehen.');
        }

        // Only explicit user changes override the game's defaults and presets.
        return "{}\n";
    }

    public static function assertEditable(Instance $instance, string $path, string $previous, string $content): void
    {
        if (!self::canInitialize($instance, $path)) {
            return;
        }
        $before = GameConfigDocument::values($previous, 'jsonc');
        $after = GameConfigDocument::values($content, 'jsonc');
        foreach ([['InactivityKillTimeMin', 'InactivityKillTimeMax'], ['GameTimeModifiers.BloodMoonFrequency_Min', 'GameTimeModifiers.BloodMoonFrequency_Max']] as [$min, $max]) {
            if (($before[$min] ?? null) === ($after[$min] ?? null) && ($before[$max] ?? null) === ($after[$max] ?? null)) {
                continue;
            }
            // A partial override must not guess the other bound from a preset.
            if (!isset($after[$min], $after[$max]) || !is_numeric($after[$min]) || !is_numeric($after[$max]) || (float) $after[$min] > (float) $after[$max]) {
                throw new \InvalidArgumentException('Bitte Unter- und Obergrenze gemeinsam festlegen; die Untergrenze darf nicht größer sein: '.$min.' / '.$max);
            }
        }
    }
}
